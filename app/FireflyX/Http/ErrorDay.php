<?php

/*
 * ErrorDay.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace App\FireflyX\Http;

use App\FireflyX\Models\ImportRun;
use App\FireflyX\Models\ManualImport;
use Carbon\Carbon;

/**
 * The errors page in Pico: every error of the imports that started on one day,
 * nightly and manual, from rows already loaded (no queries here).
 *
 * Rows from before error_messages existed carry only first_error / error, so
 * those are listed as the one message they have. A message list is capped, so
 * `more` says how many errors the list does not show.
 */
final class ErrorDay
{
    /**
     * @param iterable<ImportRun>     $runs    the day's nightly runs
     * @param iterable<ManualImport>  $manuals the day's manual imports, with their connection (removed ones too)
     * @param array<string, string>   $banks   bank name by connection name, removed connections too
     * @param null|string             $only    show only this connection
     *
     * @return array{date: string, count: int, connections: list<array{name: string, bank: null|string}>, items: list<array<string, mixed>>}
     */
    public static function build(Carbon $day, iterable $runs, iterable $manuals, array $banks, ?string $only = null): array
    {
        $entries = [];
        foreach ($runs as $run) {
            if ($run->errors > 0) {
                $entries[] = self::entry($run->created_at, $run->id, 'nightly', $run->connection_name, $banks, $run->errors, $run->error_messages, $run->first_error);
            }
        }
        foreach ($manuals as $manual) {
            $shown = $manual->present();
            if (ManualImport::FAILED === $shown['status']) {
                // A run that failed before the import reported anything has no count: it is one error.
                $entries[] = self::entry($manual->created_at, $manual->id, 'manual', $manual->connection?->name, $banks, $manual->summary['errors'] ?? 1, $manual->error_messages, $shown['error']);
            }
        }

        $connections = [];
        foreach ($entries as $entry) {
            $name = $entry['item']['connection'];
            if (null !== $name) {
                $connections[$name] = ['name' => $name, 'bank' => $entry['item']['bank']];
            }
        }
        ksort($connections);

        $entries = array_values(array_filter($entries, static fn (array $entry): bool => null === $only || $only === $entry['item']['connection']));
        usort($entries, static fn (array $a, array $b): int => [$b['at'], $b['source'], $b['id']] <=> [$a['at'], $a['source'], $a['id']]);

        return [
            'date'        => $day->format('Y-m-d'),
            'count'       => array_sum(array_map(static fn (array $entry): int => $entry['errors'], $entries)),
            'connections' => array_values($connections),
            'items'       => array_map(static fn (array $entry): array => $entry['item'], $entries),
        ];
    }

    /**
     * @param null|list<array{message: string, count: int}> $messages
     */
    private static function entry(Carbon $at, int $id, string $source, ?string $connection, array $banks, int $errors, ?array $messages, ?string $fallback): array
    {
        if (null === $messages) {
            $messages = null === $fallback || '' === $fallback ? [] : [['message' => $fallback, 'count' => 1]];
        }
        $shown = array_sum(array_column($messages, 'count'));

        return [
            'at'     => $at->getTimestamp(),
            'source' => $source,
            'id'     => $id,
            'errors' => $errors,
            'item'   => [
                'at'         => $at->toW3cString(),
                'source'     => $source,
                'connection' => $connection,
                'bank'       => null === $connection ? null : ($banks[$connection] ?? null),
                'messages'   => $messages,
                'more'       => max(0, $errors - $shown),
            ],
        ];
    }
}
