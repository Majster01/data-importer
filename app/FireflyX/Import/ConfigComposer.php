<?php

/*
 * ConfigComposer.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace App\FireflyX\Import;

use App\FireflyX\Models\Connection;
use App\FireflyX\Models\ConnectionAccount;
use App\Services\Shared\Configuration\Configuration;
use Carbon\Carbon;
use Throwable;

/**
 * base ⊕ connection ⊕ date window → the autoimport config for one connection.
 *
 * The result is the plain merge, which is exactly what the importer read from
 * the hand-exported configs before; Configuration::fromArray() only validates.
 */
final class ConfigComposer
{
    public function __construct(private readonly BaseConfig $base, private readonly int $lookbackDays) {}

    public function compose(Connection $connection, Carbon $today): array
    {
        return $this->build($connection, [
            'date_not_before'   => $this->notBefore($connection, $today)->format('Y-m-d'),
            'date_not_after'    => '',
            'date_range'        => 'partial',
            'date_range_number' => $this->lookbackDays,
            'date_range_unit'   => 'd',
        ]);
    }

    /**
     * The same config for an explicit time frame (both days included), as
     * chosen for a manual import. Everything but the window is identical.
     */
    public function composeRange(Connection $connection, Carbon $from, Carbon $to): array
    {
        return $this->build($connection, [
            'date_not_before'   => $from->format('Y-m-d'),
            'date_not_after'    => $to->format('Y-m-d'),
            'date_range'        => 'range',
            'date_range_number' => $this->lookbackDays,
            'date_range_unit'   => 'd',
        ]);
    }

    private function build(Connection $connection, array $window): array
    {
        $accounts = [];

        /** @var ConnectionAccount $account */
        foreach ($connection->mappedAccounts() as $account) {
            $accounts[$account->uid] = $account->firefly_account_id;
        }
        if ([] === $accounts) {
            throw new ConfigException(sprintf('Connection "%s" has no mapped accounts.', $connection->name));
        }
        if (null === $connection->session_id || '' === $connection->session_id) {
            throw new ConfigException(sprintf('Connection "%s" has no Enable Banking session.', $connection->name));
        }

        $config = [
            ...$this->base->values(),
            'accounts'                => $accounts,
            'created_at'              => $connection->created_at->toW3cString(),
            'enable_banking_auth_id'  => (string) $connection->auth_id,
            'enable_banking_bank'     => $connection->bank_name,
            'enable_banking_country'  => $connection->bank_country,
            'enable_banking_sessions' => [$connection->session_id],
            ...$window,
        ];

        try {
            Configuration::fromArray($config);
        } catch (Throwable $e) {
            throw new ConfigException(sprintf('The importer rejects the config for "%s": %s', $connection->name, $e->getMessage()));
        }

        return $config;
    }

    /**
     * The rolling window, reaching back further only until the connection's
     * first successful import (the listener clears import_since then).
     */
    public function notBefore(Connection $connection, Carbon $today): Carbon
    {
        $rolling = $today->copy()->startOfDay()->subDays($this->lookbackDays);
        if ($connection->import_since instanceof Carbon && $connection->import_since->lessThan($rolling)) {
            return $connection->import_since->copy()->startOfDay();
        }

        return $rolling;
    }
}
