<?php

/*
 * FakeGateway.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace Tests\Feature\FireflyX;

use App\FireflyX\EnableBanking\BankAccount;
use App\FireflyX\EnableBanking\EnableBankingGateway;
use App\FireflyX\EnableBanking\SessionDetails;
use Carbon\Carbon;
use RuntimeException;

/**
 * Enable Banking as the tests need it, recording every call.
 */
final class FakeGateway implements EnableBankingGateway
{
    public array $calls            = [];
    public array $sessions         = [];
    public array $accountDetails   = [];
    public int $maxConsent         = 15_552_000; // 180 days
    public ?array $nextSession     = null;
    public bool $failCreateSession = false;
    public bool $failStartAuth     = false;

    /** @var array<string, list<array>> country => banks */
    public array $banks = [];

    public function getSession(string $sessionId): SessionDetails
    {
        $this->calls[] = ['getSession', $sessionId];
        if (!array_key_exists($sessionId, $this->sessions)) {
            throw new RuntimeException('SESSION_DOES_NOT_EXIST');
        }

        return new SessionDetails($this->sessions[$sessionId]);
    }

    public function getAccountDetails(string $accountUid): BankAccount
    {
        $this->calls[] = ['getAccountDetails', $accountUid];

        return BankAccount::fromEnableBanking(['uid' => $accountUid, ...($this->accountDetails[$accountUid] ?? [])]);
    }

    public function listBanks(string $country): array
    {
        $this->calls[] = ['listBanks', $country];

        return $this->banks[$country] ?? [];
    }

    public function maxConsentSeconds(string $country, string $bank): int
    {
        $this->calls[] = ['maxConsentSeconds', $country, $bank];

        return $this->maxConsent;
    }

    public function startAuthorisation(string $country, string $bank, string $psuType, string $state, string $redirectUrl, Carbon $validUntil): array
    {
        $this->calls[] = ['startAuthorisation', compact('country', 'bank', 'psuType', 'state', 'redirectUrl', 'validUntil')];
        if ($this->failStartAuth) {
            throw new RuntimeException('Enable Banking API error: 422');
        }

        return ['url' => 'https://tilisy.example/auth?state='.$state, 'auth_id' => 'new-auth-id'];
    }

    public function createSession(string $code): array
    {
        $this->calls[] = ['createSession', $code];
        if ($this->failCreateSession || null === $this->nextSession) {
            throw new RuntimeException('Enable Banking API error: 422');
        }

        return [
            'session_id'  => $this->nextSession['session_id'],
            'valid_until' => Carbon::now()->addDays(179),
            'accounts'    => array_map(static fn (array $account): BankAccount => BankAccount::fromEnableBanking($account), $this->nextSession['accounts']),
        ];
    }

    public function deleteSession(string $sessionId): void
    {
        $this->calls[] = ['deleteSession', $sessionId];
    }

    public function diagnoseSession(string $sessionId): void
    {
        $this->calls[] = ['diagnoseSession', $sessionId];
    }

    public function called(string $method): array
    {
        return array_values(array_filter($this->calls, static fn (array $call): bool => $call[0] === $method));
    }
}
