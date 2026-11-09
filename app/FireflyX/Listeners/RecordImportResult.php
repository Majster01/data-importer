<?php

/*
 * RecordImportResult.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace App\FireflyX\Listeners;

use App\Events\ImportedTransactions;
use App\FireflyX\Import\BankSideFailure;
use App\FireflyX\Models\Connection;
use App\FireflyX\Models\ImportRun;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Upstream's autoimport fires ImportedTransactions once per config file, on
 * success and on every failure path. Recording it per connection is what lets
 * the nightly report prove each connection was really imported, and lets Pico
 * show "last import". Every night also adds an ImportRun, for the history.
 */
final class RecordImportResult
{
    public const int MAX_MESSAGES       = 50;
    public const int MAX_MESSAGE_LENGTH = 1000;

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

        $messages = $summary['errors'] > 0 ? self::errorMessages($event) : null;
        ImportRun::create([
            'connection_id'   => $connection->id,
            'connection_name' => $connection->name,
            'ok'              => $connection->last_import_ok,
            'created'         => $summary['messages'],
            'warnings'        => $summary['warnings'],
            'errors'          => $summary['errors'],
            'first_error'     => $summary['first_error'],
            'error_messages'  => $messages,
            'created_at'      => $connection->last_import_at,
        ]);
        if (null !== $messages) {
            Log::info(sprintf('FireflyX: import of %s kept %d distinct error message(s) of %d.', $connection->name, count($messages), $summary['errors']));
        }
        ImportRun::where('created_at', '<', Carbon::now()->subDays((int) config('fireflyx.history_days')))->delete();
    }

    /**
     * @return array{messages: int, warnings: int, errors: int, first_error: null|string}
     */
    public static function summarize(ImportedTransactions $event): array
    {
        $errors = self::errors($event);
        $first  = $errors[0] ?? null;
        // A failure on the bank's side reads as one, not as a raw HTTP error.
        if (is_string($first) && BankSideFailure::matches($first)) {
            $first = BankSideFailure::SUMMARY;
        }

        return [
            'messages'    => count(self::flatten($event->messages)),
            'warnings'    => count(self::flatten($event->warnings)),
            'errors'      => count($errors),
            'first_error' => $first,
        ];
    }

    /**
     * Every error of the import as the bank and the importer worded it: the
     * list is for finding out what went wrong, so no friendlier text here.
     * Identical messages are counted once, cut by characters (not bytes) so
     * the JSON stays valid, and only the first MAX_MESSAGES distinct ones are
     * kept; the true number of errors stays in summarize().
     *
     * @return list<array{message: string, count: int}>
     */
    public static function errorMessages(ImportedTransactions $event): array
    {
        $counts = [];
        foreach (self::errors($event) as $error) {
            $text = mb_substr(mb_scrub((string) $error), 0, self::MAX_MESSAGE_LENGTH);
            $key  = 'm'.$text;
            if (!isset($counts[$key])) {
                $counts[$key] = ['message' => $text, 'count' => 0];
            }
            ++$counts[$key]['count'];
        }

        return array_slice(array_values($counts), 0, self::MAX_MESSAGES);
    }

    /**
     * eb003: the bank had nothing new to download. That is a successful import of 0 new, not an error.
     */
    private static function errors(ImportedTransactions $event): array
    {
        return array_values(array_filter(
            self::flatten($event->errors),
            static fn ($error): bool => !is_string($error) || (false === stripos($error, '[eb003]') && false === stripos($error, 'No transactions were downloaded from Enable Banking'))
        ));
    }

    private static function flatten(array $grouped): array
    {
        return array_merge([], ...array_values($grouped));
    }
}
