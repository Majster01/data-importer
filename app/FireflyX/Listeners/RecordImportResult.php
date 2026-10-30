<?php

/*
 * RecordImportResult.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace App\FireflyX\Listeners;

use App\Events\ImportedTransactions;
use App\FireflyX\Models\Connection;
use App\FireflyX\Models\ImportRun;
use Carbon\Carbon;

/**
 * Upstream's autoimport fires ImportedTransactions once per config file, on
 * success and on every failure path. Recording it per connection is what lets
 * the nightly report prove each connection was really imported, and lets Pico
 * show "last import". Every night also adds an ImportRun, for the history.
 */
final class RecordImportResult
{
    public function handle(ImportedTransactions $event): void
    {
        $name       = basename($event->configurationFile, '.json');
        $connection = Connection::where('name', $name)->first();
        if (null === $connection) {
            return;
        }
        $summary                         = self::summarize($event);
        $connection->last_import_at      = Carbon::now();
        $connection->last_import_ok      = 0 === $summary['errors'];
        $connection->last_import_summary = $summary;
        // History before the rolling window is only needed until it is in.
        if (0 === $summary['errors']) {
            $connection->import_since = null;
        }
        $connection->save();

        ImportRun::create([
            'connection_id'   => $connection->id,
            'connection_name' => $connection->name,
            'ok'              => $connection->last_import_ok,
            'created'         => $summary['messages'],
            'warnings'        => $summary['warnings'],
            'errors'          => $summary['errors'],
            'first_error'     => $summary['first_error'],
            'created_at'      => $connection->last_import_at,
        ]);
        ImportRun::where('created_at', '<', Carbon::now()->subDays((int) config('fireflyx.history_days')))->delete();
    }

    /**
     * @return array{messages: int, warnings: int, errors: int, first_error: null|string}
     */
    public static function summarize(ImportedTransactions $event): array
    {
        $errors = self::flatten($event->errors);

        return [
            'messages'    => count(self::flatten($event->messages)),
            'warnings'    => count(self::flatten($event->warnings)),
            'errors'      => count($errors),
            'first_error' => $errors[0] ?? null,
        ];
    }

    private static function flatten(array $grouped): array
    {
        return array_merge([], ...array_values($grouped));
    }
}
