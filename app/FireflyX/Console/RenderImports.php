<?php

/*
 * RenderImports.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace App\FireflyX\Console;

use App\FireflyX\Import\BaseConfig;
use App\FireflyX\Import\ConfigComposer;
use App\FireflyX\Import\ConfigException;
use App\FireflyX\Models\Connection;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Writes one <name>.json per importable connection into the autoimport
 * directory, right before the nightly /autoimport call reads it.
 *
 * /autoimport only accepts a directory, so the database is the source of truth
 * and these files are regenerated every night: a connection saved in Pico
 * during the day is picked up without any deploy or restart.
 */
final class RenderImports extends Command
{
    protected $description = 'Render every importable bank connection into the autoimport directory.';
    protected $signature   = 'fireflyx:render {--dry-run : Print the configs (sessions masked) instead of writing them}';

    public function handle(): int
    {
        try {
            $base = BaseConfig::load((string) config('fireflyx.base_config'));
        } catch (ConfigException $e) {
            // Nothing is written or pruned: yesterday's files stay, and the
            // nightly run alerts on the non-zero exit.
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $composer = new ConfigComposer($base, (int) config('fireflyx.lookback_days'));
        $dir      = rtrim((string) config('fireflyx.import_dir'), '/');
        $dryRun   = (bool) $this->option('dry-run');
        $today    = Carbon::today();
        $failed   = false;
        $written  = [];

        if (!$dryRun && !is_dir($dir)) {
            $this->error(sprintf('Import directory "%s" does not exist.', $dir));

            return self::FAILURE;
        }

        foreach (Connection::with('accounts')->orderBy('name')->get() as $connection) {
            if (!$connection->isImportable()) {
                $this->line(sprintf('skip %s: %s', $connection->name, $connection->status()));

                continue;
            }

            try {
                $config = $composer->compose($connection, $today);
            } catch (ConfigException $e) {
                // One broken connection must not stop the others.
                $this->error($e->getMessage());
                $failed = true;

                continue;
            }

            $summary = sprintf(
                '%s → %d account(s), since %s',
                $connection->name,
                count($config['accounts']),
                $config['date_not_before']
            );

            if ($dryRun) {
                $config['enable_banking_sessions'] = ['<masked>'];
                $config['enable_banking_auth_id']  = '<masked>';
                $this->line($summary);
                $this->line((string) json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

                continue;
            }

            $this->writeAtomically($dir, $connection->configFileName(), $config);
            $written[] = $connection->configFileName();
            $this->line($summary);
        }

        if (!$dryRun) {
            $this->prune($dir, $written);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function writeAtomically(string $dir, string $file, array $config): void
    {
        // The random suffix is the extension, so upstream's scandir() (which
        // imports every *.json) can never pick up a half-written file.
        $tmp = sprintf('%s/.%s.%s', $dir, $file, bin2hex(random_bytes(4)));
        file_put_contents($tmp, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        chmod($tmp, 0600);
        rename($tmp, sprintf('%s/%s', $dir, $file));
    }

    /**
     * Removed, expired and unmapped connections must stop importing, so every
     * *.json not rendered tonight goes.
     */
    private function prune(string $dir, array $written): void
    {
        foreach (glob(sprintf('%s/*.json', $dir)) ?: [] as $path) {
            if (!in_array(basename($path), $written, true)) {
                unlink($path);
                $this->line(sprintf('pruned %s', basename($path)));
            }
        }
    }
}
