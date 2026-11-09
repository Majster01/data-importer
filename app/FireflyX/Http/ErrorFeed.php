<?php

/*
 * ErrorFeed.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace App\FireflyX\Http;

use App\FireflyX\Models\ImportRun;
use App\FireflyX\Models\ManualImport;

/**
 * The errors page in Pico: every error of the nightly and the manual imports
 * as one entry each, newest import first, from rows already loaded (no
 * queries here).
 *
 * An entry is one distinct message of one import, with how many times that
 * import had it. An import keeps only a capped list of messages, so what the
 * list does not show is one more entry without a message. Rows from before
 * error_messages existed carry only first_error / error, and those are listed
 * as the one message they have.
 */
final class ErrorFeed
{
    public const array SOURCES = ['nightly', 'manual'];

    /**
     * @param iterable<ImportRun>    $runs    nightly runs
     * @param iterable<ManualImport> $manuals manual imports, with their connection (removed ones too)
     * @param array<string, string>  $banks   bank name by connection name, removed connections too
     * @param array{connection?: null|string, source?: null|string, q?: null|string} $filter
     *
     * @return array{total: int, page: int, has_more: bool, connections: list<array{name: string, bank: null|string}>, items: list<array<string, mixed>>}
     */
    public static function build(iterable $runs, iterable $manuals, array $banks, array $filter, int $page, int $limit): array
    {
        $imports = self::imports($runs, $manuals, $banks);

        $connections = [];
        foreach ($imports as $import) {
            if (null !== $import['connection']) {
                $connections[$import['connection']] = ['name' => $import['connection'], 'bank' => $import['bank']];
            }
        }
        ksort($connections);

        $needle  = isset($filter['q']) && '' !== trim($filter['q']) ? trim($filter['q']) : null;
        $entries = [];
        foreach ($imports as $import) {
            if ((isset($filter['connection']) && $filter['connection'] !== $import['connection']) || (isset($filter['source']) && $filter['source'] !== $import['source'])) {
                continue;
            }
            foreach (self::entries($import, $needle) as $entry) {
                $entries[] = $entry;
            }
        }
        $offset = ($page - 1) * $limit;

        return [
            'total'       => count($entries),
            'page'        => $page,
            'has_more'    => count($entries) > $offset + $limit,
            'connections' => array_values($connections),
            'items'       => array_slice($entries, $offset, $limit),
        ];
    }

    /**
     * Newest first; two at the same second go to the newer row.
     *
     * @return list<array<string, mixed>>
     */
    private static function imports(iterable $runs, iterable $manuals, array $banks): array
    {
        $imports = [];
        foreach ($runs as $run) {
            if ($run->errors > 0) {
                $imports[] = self::import($run->created_at, $run->id, 'nightly', $run->connection_name, $banks, $run->errors, $run->error_messages, $run->first_error);
            }
        }
        foreach ($manuals as $manual) {
            $shown = $manual->present();
            if (ManualImport::FAILED === $shown['status']) {
                // A failed run is at least one error: one that failed before the import reported anything, or died after it, has no count of its own.
                $imports[] = self::import($manual->created_at, $manual->id, 'manual', $manual->connection?->name, $banks, max(1, $manual->summary['errors'] ?? 0), $manual->error_messages, $shown['error']);
            }
        }
        usort($imports, static fn (array $a, array $b): int => [$b['stamp'], $b['source'], $b['id']] <=> [$a['stamp'], $a['source'], $a['id']]);

        return $imports;
    }

    /**
     * @param null|list<array{message: string, count: int}> $messages
     */
    private static function import(\DateTimeInterface $at, int $id, string $source, ?string $connection, array $banks, int $errors, ?array $messages, ?string $fallback): array
    {
        if (null === $messages) {
            $messages = null === $fallback || '' === $fallback ? [] : [['message' => $fallback, 'count' => 1]];
        }

        return [
            'stamp'      => $at->getTimestamp(),
            'at'         => $at->format(\DATE_W3C),
            'id'         => $id,
            'source'     => $source,
            'connection' => $connection,
            'bank'       => null === $connection ? null : ($banks[$connection] ?? null),
            'messages'   => $messages,
            'more'       => max(0, $errors - array_sum(array_column($messages, 'count'))),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function entries(array $import, ?string $needle): array
    {
        $entries = [];
        foreach ($import['messages'] as $index => $message) {
            if (null === $needle || false !== mb_stripos($message['message'], $needle)) {
                $entries[] = self::entry($import, (string) $index, $message['message'], $message['count']);
            }
        }
        // What the list does not show has no text to search
        if ($import['more'] > 0 && null === $needle) {
            $entries[] = self::entry($import, 'more', null, $import['more']);
        }

        return $entries;
    }

    private static function entry(array $import, string $key, ?string $message, int $count): array
    {
        return [
            'id'         => sprintf('%s-%d-%s', $import['source'], $import['id'], $key),
            'at'         => $import['at'],
            'source'     => $import['source'],
            'connection' => $import['connection'],
            'bank'       => $import['bank'],
            'message'    => $message,
            'count'      => $count,
        ];
    }
}
