<?php

/*
 * SessionRefresher.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace App\FireflyX\EnableBanking;

use App\FireflyX\Models\Connection;
use App\FireflyX\Models\ConnectionAccount;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Asks Enable Banking for a connection's consent status and expiry, and fills
 * in what each account needs for Pico and for a later re-link: the
 * identification_hash, IBAN, name and currency.
 */
final class SessionRefresher
{
    public function __construct(private readonly EnableBankingGateway $gateway) {}

    public function refresh(Connection $connection): Connection
    {
        if (null === $connection->session_id || '' === $connection->session_id) {
            return $connection;
        }

        try {
            $details = $this->gateway->getSession($connection->session_id);
        } catch (Throwable $e) {
            // Not only ImporterHttpException: a bad key fails in the JWT
            // library, and upstream's authenticatedGet() itself errors on a
            // connection failure (no response to read a status code from).
            // One connection's failure must never break the list or the run.
            $connection->last_error = $e->getMessage();
            $connection->checked_at = Carbon::now();
            $connection->save();

            return $connection;
        }

        $connection->eb_status   = $details->status;
        $connection->valid_until = $details->validUntil;
        $connection->checked_at  = Carbon::now();
        $connection->last_error  = null;
        $connection->save();

        /** @var ConnectionAccount $account */
        foreach ($connection->accounts as $account) {
            $hash = $details->identificationHashes[$account->uid] ?? '';
            if ('' !== $hash) {
                $account->identification_hash = $hash;
            }
            // Details only work while the consent is valid, and never change.
            if (null === $account->iban && 'AUTHORIZED' === $details->status) {
                $this->fillDetails($account);
            }
            $account->save();
        }

        return $connection;
    }

    private function fillDetails(ConnectionAccount $account): void
    {
        try {
            $details = $this->gateway->getAccountDetails($account->uid);
        } catch (Throwable $e) {
            Log::warning(sprintf('FireflyX: no details for account %s: %s', $account->uid, $e->getMessage()));

            return;
        }
        $account->iban     = '' !== $details->iban ? $details->iban : null;
        $account->name     = $details->name;
        $account->currency = '' !== $details->currency ? $details->currency : null;
        if ('' !== $details->identificationHash) {
            $account->identification_hash = $details->identificationHash;
        }
    }
}
