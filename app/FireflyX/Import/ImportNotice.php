<?php

/*
 * ImportNotice.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace App\FireflyX\Import;

use App\FireflyX\Models\Connection;

/**
 * What the nightly run tells the user about one connection's import, in words
 * for a phone: a success with how many transactions came in, or what went
 * wrong, what to do about it, and where the exact error is. The text is
 * classified from the first error the importer recorded; the error itself is
 * never copied into the notification (it can be long, and it is in the logs).
 *
 * The result is what Pocket's notify endpoint takes: event, title (at most
 * 100 characters), body (at most 300), path, and count for a success.
 */
final class ImportNotice
{
    public const string SUCCESS = 'import.success';
    public const string FAILURE = 'import.failure';

    private const string LOGS = 'Exact error: importer logs.';

    /**
     * @param null|array{messages: int, warnings: int, errors: int, first_error: null|string} $summary null when the connection was not imported in this run
     *
     * @return array{event: string, title: string, body: string, path: string, count: null|int}
     */
    public static function for(Connection $connection, ?array $summary): array
    {
        $label = self::label($connection);
        $bank  = '' !== $connection->bank_name ? $connection->bank_name : $connection->name;

        if (null !== $summary && 0 === $summary['errors']) {
            $count = $summary['messages'];

            return [
                'event' => self::SUCCESS,
                'title' => self::cut(sprintf('%s import succeeded', $label), 100),
                'body'  => 0 === $count
                    ? 'Nothing new since the last import.'
                    : sprintf('%d new transaction%s imported.', $count, 1 === $count ? '' : 's'),
                'path'  => '/transactions',
                'count' => $count,
            ];
        }

        $errors = $summary['errors'] ?? 0;
        $body   = null === $summary
            ? sprintf('The nightly run did not import %s at all. Open Bank connections to see its status. %s', $bank, self::LOGS)
            : sprintf('%s%s', self::explain($summary['first_error'] ?? '', $bank, $errors), ' '.self::LOGS);

        return [
            'event' => self::FAILURE,
            'title' => self::cut(sprintf('%s import failed', $label), 100),
            'body'  => self::cut($body, 300),
            'path'  => '/bank-connections',
            'count' => null,
        ];
    }

    /** The account when the connection feeds one, otherwise the bank. */
    public static function label(Connection $connection): string
    {
        $mapped = $connection->mappedAccounts();
        $name   = 1 === $mapped->count() ? trim((string) $mapped->first()->name) : '';

        if ('' !== $name) {
            return $name;
        }

        return '' !== $connection->bank_name ? $connection->bank_name : $connection->name;
    }

    private static function explain(string $firstError, string $bank, int $errors): string
    {
        $error = strtolower($firstError);

        return match (true) {
            BankSideFailure::matches($error)
                => sprintf('%s had a problem on its side, so nothing could be imported. Nothing is wrong with your connection; the next import tries again.', $bank),
            str_contains($error, 'a112'), str_contains($error, 'a113'), str_contains($error, 'a114'), str_contains($error, 'eua') && str_contains($error, 'expired'), str_contains($error, 'session') && (str_contains($error, 'expired') || str_contains($error, 'closed'))
                => sprintf('The consent for %s has ended, so nothing was imported. Re-authenticate it in Bank connections.', $bank),
            str_contains($error, '429'), str_contains($error, 'too many'), str_contains($error, 'rate limit')
                => sprintf('%s limited how often it can be asked for transactions. Nothing was lost; tomorrow\'s import catches up.', $bank),
            str_contains($error, 'enable banking api error: 401'), str_contains($error, 'enable banking api error: 403')
                => sprintf('Enable Banking refused the request for %s. Its consent may have been withdrawn at the bank: try Re-authenticate in Bank connections.', $bank),
            str_contains($error, 'eb001')
                => sprintf('Transactions could not be downloaded from %s. It is usually temporary and the next import tries again.', $bank),
            str_contains($error, 'a116'), str_contains($error, 'a118'), str_contains($error, 'a119'), str_contains($error, 'a120'), str_contains($error, 'a121'), str_contains($error, 'firefly')
                => sprintf('Firefly III did not accept some transactions from %s. The rest were imported.', $bank),
            default
                => sprintf('The import of %s finished with %d error%s.', $bank, max(1, $errors), 1 === max(1, $errors) ? '' : 's'),
        };
    }

    private static function cut(string $text, int $max): string
    {
        return mb_strlen($text) <= $max ? $text : mb_substr($text, 0, $max - 1).'…';
    }
}
