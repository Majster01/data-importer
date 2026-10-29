<?php

/*
 * EnableBankingGateway.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace App\FireflyX\EnableBanking;

use Carbon\Carbon;

/**
 * Every Enable Banking call the fork makes. The real implementation wraps
 * upstream's request classes; tests bind a fake.
 */
interface EnableBankingGateway
{
    public function getSession(string $sessionId): SessionDetails;

    public function getAccountDetails(string $accountUid): BankAccount;

    /** Seconds; the bank's own limit, or 89 days when it cannot be read. */
    public function maxConsentSeconds(string $country, string $bank): int;

    /** @return array{url: string, auth_id: string} */
    public function startAuthorisation(string $country, string $bank, string $psuType, string $state, string $redirectUrl, Carbon $validUntil): array;

    /** @return array{session_id: string, valid_until: ?Carbon, accounts: list<BankAccount>} */
    public function createSession(string $code): array;

    public function deleteSession(string $sessionId): void;
}
