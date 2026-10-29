<?php

/*
 * ReportImports.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace App\FireflyX\Console;

use App\FireflyX\Models\Connection;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Run after the nightly /autoimport: every connection that was rendered must
 * have an import result from this run, and it must be clean. This is what
 * catches a connection that silently was not imported at all.
 */
final class ReportImports extends Command
{
    protected $description = 'Check that every importable connection was imported since --since.';
    protected $signature   = 'fireflyx:connections:report
        {--since= : When the import started (ISO 8601)}
        {--json : Print the result as JSON}';

    public function handle(): int
    {
        $since = (string) $this->option('since');
        if ('' === $since) {
            $this->error('--since is required.');

            return self::FAILURE;
        }
        $since  = Carbon::parse($since);
        $rows   = [];
        $failed = false;

        foreach (Connection::with('accounts')->orderBy('name')->get() as $connection) {
            if (!$connection->isImportable()) {
                continue;
            }
            $imported = $connection->last_import_at instanceof Carbon && $connection->last_import_at->greaterThanOrEqualTo($since);
            $ok       = $imported && true === $connection->last_import_ok;
            $failed   = $failed || !$ok;
            $rows[]   = [
                'name'     => $connection->name,
                'imported' => $imported,
                'ok'       => $ok,
                'summary'  => $imported ? $connection->last_import_summary : null,
            ];
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $failed ? self::FAILURE : self::SUCCESS;
        }
        foreach ($rows as $row) {
            $this->line(match (true) {
                !$row['imported'] => sprintf('! %s: NOT imported in this run', $row['name']),
                !$row['ok']       => sprintf('! %s: imported with %d error(s): %s', $row['name'], $row['summary']['errors'], $row['summary']['first_error']),
                default           => sprintf('%s: ok, %d message(s), %d warning(s)', $row['name'], $row['summary']['messages'], $row['summary']['warnings']),
            });
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
