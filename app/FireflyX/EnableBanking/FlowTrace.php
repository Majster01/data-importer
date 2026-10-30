<?php

/*
 * FlowTrace.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace App\FireflyX\EnableBanking;

use Illuminate\Support\Facades\Log;

/**
 * Debug trace of the consent flow, for working out why a bank returns what it
 * does. Off unless FIREFLYX_EB_TRACE=true.
 *
 * Every line starts with "FireflyX EB-TRACE <event>" so one grep finds the
 * whole flow:
 *
 *   docker compose logs importer | grep 'EB-TRACE'
 *
 * By default nothing personal is written: IBANs are cut to their first and last
 * four characters, names are reduced to "present, N characters", and session
 * ids to their first eight. FIREFLYX_EB_TRACE_RAW=true also writes Enable
 * Banking's responses as they came, IBANs and names included (the authorisation
 * code and session ids stay cut). Switch it off again once you have what you need.
 */
final class FlowTrace
{
    public static function enabled(): bool
    {
        return (bool) config('fireflyx.eb_trace');
    }

    public static function raw(): bool
    {
        return self::enabled() && (bool) config('fireflyx.eb_trace_raw');
    }

    public static function event(string $event, array $context = []): void
    {
        if (!self::enabled()) {
            return;
        }
        Log::info('FireflyX EB-TRACE '.$event, $context);
    }

    /**
     * What POST /sessions or GET /sessions/{id} said, in a form that shows the
     * shape: which keys exist, how many accounts and what each one carries.
     *
     * @return array<string, mixed>
     */
    public static function describeSession(array $json): array
    {
        $out = [
            'keys'         => array_keys($json),
            'session_id'   => self::cut(self::scalar($json['session_id'] ?? $json['id'] ?? null)),
            'status'       => $json['status'] ?? null,
            'authorized'   => $json['authorized'] ?? null,
            'created'      => $json['created'] ?? null,
            'psu_type'     => $json['psu_type'] ?? null,
            'aspsp'        => $json['aspsp'] ?? null,
            'access'       => $json['access'] ?? null,
            'accounts'     => self::describeList($json['accounts'] ?? null),
            'accounts_data'=> self::describeList($json['accounts_data'] ?? null),
        ];
        // Anything else the bank or Enable Banking added, so nothing hides in a key we never read.
        foreach ($json as $key => $value) {
            if (!array_key_exists($key, $out) && !in_array($key, ['id'], true)) {
                $out['other'][$key] = is_scalar($value) || null === $value ? $value : self::describeList($value);
            }
        }
        if (self::raw()) {
            $out['raw'] = self::maskSecrets($json);
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    public static function describeAccount(array $account): array
    {
        $name = $account['name'] ?? null;

        return [
            'keys'               => array_keys($account),
            'uid'                => $account['uid'] ?? null,
            'iban'               => self::maskId(self::scalar($account['account_id']['iban'] ?? $account['iban'] ?? null)),
            'account_id_keys'    => is_array($account['account_id'] ?? null) ? array_keys($account['account_id']) : null,
            'all_account_ids'    => self::describeIds($account['all_account_ids'] ?? null),
            'name'               => is_string($name) ? sprintf('present, %d characters', mb_strlen($name)) : (null === $name ? 'missing' : gettype($name)),
            'details'            => isset($account['details']) ? 'present' : 'missing',
            'account_servicer'   => isset($account['account_servicer']) ? 'present' : 'missing',
            'usage'              => $account['usage'] ?? null,
            'cash_account_type'  => $account['cash_account_type'] ?? null,
            'product'            => $account['product'] ?? null,
            'currency'           => $account['currency'] ?? null,
            'psu_status'         => $account['psu_status'] ?? null,
            'identification_hash'   => isset($account['identification_hash']) ? 'present' : 'missing',
            'identification_hashes' => is_array($account['identification_hashes'] ?? null) ? count($account['identification_hashes']) : 'missing',
        ];
    }

    /**
     * The URL Enable Banking sends the user to, without its query values (they
     * carry the authorisation's own id).
     */
    public static function describeUrl(string $url): array
    {
        $parts = parse_url($url) ?: [];
        parse_str((string) ($parts['query'] ?? ''), $query);

        return [
            'host'       => $parts['host'] ?? null,
            'path'       => $parts['path'] ?? null,
            'query_keys' => array_keys($query),
        ];
    }

    /**
     * The query string a bank (through Enable Banking) sent back to the callback.
     * The code and the state are secrets of the flow, so only their size is kept.
     *
     * @param array<string, mixed> $query
     *
     * @return array<string, mixed>
     */
    public static function describeCallbackQuery(array $query): array
    {
        $out = [];
        foreach ($query as $key => $value) {
            $out[$key] = match (true) {
                'code' === $key  => is_string($value) ? sprintf('present, %d characters', strlen($value)) : gettype($value),
                'state' === $key => is_string($value) ? self::cut($value, 6) : gettype($value),
                default          => $value,
            };
        }

        return $out;
    }

    public static function maskId(?string $id): ?string
    {
        if (null === $id || '' === $id) {
            return $id;
        }

        return strlen($id) <= 8 ? str_repeat('*', strlen($id)) : sprintf('%s…%s (%d)', substr($id, 0, 4), substr($id, -4), strlen($id));
    }

    private static function cut(?string $value, int $keep = 8): ?string
    {
        if (null === $value || '' === $value) {
            return $value;
        }

        return strlen($value) <= $keep ? $value : substr($value, 0, $keep).'…';
    }

    private static function scalar(mixed $value): ?string
    {
        return is_scalar($value) ? (string) $value : null;
    }

    /**
     * @return array<string, mixed>|string|null
     */
    private static function describeList(mixed $list): array|string|null
    {
        if (null === $list) {
            return 'missing';
        }
        if (!is_array($list)) {
            return gettype($list);
        }
        $items = [];
        foreach ($list as $item) {
            $items[] = match (true) {
                is_array($item)  => self::describeAccount($item),
                is_string($item) => $item, // GET /sessions lists account uids
                default          => gettype($item),
            };
        }

        return ['count' => count($list), 'items' => $items];
    }

    private static function describeIds(mixed $ids): mixed
    {
        if (!is_array($ids)) {
            return null === $ids ? 'missing' : gettype($ids);
        }

        return array_map(static fn (mixed $id): mixed => is_array($id)
            ? array_map(static fn (mixed $value): mixed => is_string($value) ? self::maskId($value) : $value, $id)
            : $id, $ids);
    }

    /**
     * @return array<string, mixed>
     */
    private static function maskSecrets(array $json): array
    {
        foreach (['session_id', 'id', 'code'] as $key) {
            if (isset($json[$key]) && is_string($json[$key])) {
                $json[$key] = self::cut($json[$key]);
            }
        }

        return $json;
    }
}
