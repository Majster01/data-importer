<?php

/*
 * RunManualImport.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace App\FireflyX\Console;

use App\FireflyX\Import\BaseConfig;
use App\FireflyX\Import\ConfigException;
use App\FireflyX\Import\ConfigComposer;
use App\FireflyX\Import\ConfigImporter;
use App\FireflyX\Listeners\RecordImportResult;
use App\FireflyX\Models\ManualImport;
use App\FireflyX\Suggester\SuggesterClient;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * One manual import: the saved connection's config over the chosen time frame,
 * then the category suggester over the same time frame. Started detached by
 * ManualImportLauncher; Pico reads the progress from the manual_imports row.
 */
final class RunManualImport extends Command
{
    protected $description = 'Run a manual import started from Pico (internal).';
    protected $hidden      = true;
    protected $signature   = 'fireflyx:manual-import {id : The fireflyx_manual_imports row}';

    public function handle(ConfigImporter $importer, SuggesterClient $suggester): int
    {
        $run = ManualImport::with('connection.accounts')->find((int) $this->argument('id'));
        // The conditional update makes a second start of the same row lose.
        if (null === $run || 1 !== ManualImport::whereKey($run->id)->where('status', ManualImport::QUEUED)->update(['status' => ManualImport::IMPORTING])) {
            $this->error('No such queued manual import.');

            return self::FAILURE;
        }
        $run->refresh();

        $previous = $this->tagWithPsu($run);
        try {
            return $this->importAndSuggest($run, $importer, $suggester);
        } finally {
            // The user's address is only for this run.
            config($previous);
            $run->update(['psu_ip' => null, 'psu_user_agent' => null]);
        }
    }

    /**
     * The bank counts a request with PSU headers as user-initiated. The importer
     * reads them from config, and this process is the manual run's alone (the
     * nightly import is another process), so setting them here tags this run only.
     *
     * @return array<string, mixed> the config to put back afterwards
     */
    private function tagWithPsu(ManualImport $run): array
    {
        $previous = [];
        foreach (['eb.add_import_ip_header', 'eb.import_ip', 'eb.psu_user_agent'] as $key) {
            $previous[$key] = config($key);
        }
        if (null !== $run->psu_ip) {
            config(['eb.add_import_ip_header' => true, 'eb.import_ip' => $run->psu_ip]);
        }
        if (null !== $run->psu_user_agent) {
            config(['eb.psu_user_agent' => $run->psu_user_agent]);
        }
        // Never the address itself; which part is missing says why a run was not fully tagged.
        Log::info(sprintf('FireflyX: manual import %d psu_tagged: ip %s, user agent %s.', $run->id, null === $run->psu_ip ? 'missing' : 'sent', null === $run->psu_user_agent ? 'missing' : 'sent'));

        return $previous;
    }

    private function importAndSuggest(ManualImport $run, ConfigImporter $importer, SuggesterClient $suggester): int
    {
        $file = $this->writeConfig($run);
        if (null === $file) {
            return self::FAILURE;
        }

        try {
            $outcome = $importer->import($file);
        } catch (Throwable $e) {
            $this->failRun($run, $e->getMessage());

            return self::FAILURE;
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
        }

        $event = $outcome['result'];
        if (null === $event) {
            $this->failRun($run, sprintf('The importer could not run (exit code %d).', $outcome['exit_code']));

            return self::FAILURE;
        }
        $summary = RecordImportResult::summarize($event);
        $run->update(['summary' => $summary]);
        if ($summary['errors'] > 0) {
            $messages = RecordImportResult::errorMessages($event);
            $run->update(['error_messages' => $messages]);
            Log::info(sprintf('FireflyX: manual import %d kept %d distinct error message(s) of %d.', $run->id, count($messages), $summary['errors']));
            $this->failRun($run, (string) $summary['first_error']);

            return self::FAILURE;
        }

        $this->suggest($run, $suggester);

        return self::SUCCESS;
    }

    /**
     * The saved connection's config with only the time frame replaced. It is
     * kept out of the directory the nightly /autoimport scans, and out of
     * RecordImportResult's reach (its name is not a connection's), so a manual
     * import never counts as the night's import.
     */
    private function writeConfig(ManualImport $run): ?string
    {
        try {
            $composer = new ConfigComposer(BaseConfig::load((string) config('fireflyx.base_config')), (int) config('fireflyx.lookback_days'));
            $config   = $composer->composeRange($run->connection, $run->date_from, $run->date_to);
            $dir      = rtrim((string) config('fireflyx.import_dir'), '/').'/manual';
            if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
                throw new ConfigException(sprintf('Cannot create "%s".', $dir));
            }
            $file = sprintf('%s/%s.manual-%d.json', $dir, $run->connection->name, $run->id);
            file_put_contents($file, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
            chmod($file, 0600);

            return $file;
        } catch (Throwable $e) {
            $this->failRun($run, $e->getMessage());

            return null;
        }
    }

    private function suggest(ManualImport $run, SuggesterClient $suggester): void
    {
        $run->update(['status' => ManualImport::SUGGESTING]);
        $error = null;
        if (!$suggester->configured()) {
            $error = 'The category suggester is not configured.';
        } else {
            try {
                $run->suggestions = $suggester->suggest($run->date_from, $run->date_to);
            } catch (Throwable $e) {
                $error = $e->getMessage();
            }
        }
        // The transactions are in either way, so this is done, not failed.
        $run->suggest_error = $error;
        $run->status        = ManualImport::DONE;
        $run->finished_at   = Carbon::now();
        $run->save();
    }

    private function failRun(ManualImport $run, string $error): void
    {
        $this->error($error);
        $run->update(['status' => ManualImport::FAILED, 'error' => $error, 'finished_at' => Carbon::now()]);
    }
}
