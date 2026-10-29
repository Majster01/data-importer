<?php

/*
 * UpstreamGateway.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace App\FireflyX\EnableBanking;

use App\Services\EnableBanking\Model\Bank;
use App\Services\EnableBanking\Request\GetASPSPsRequest;
use App\Services\EnableBanking\Request\PostAuthRequest;
use App\Services\EnableBanking\Request\PostSessionRequest;
use App\Services\EnableBanking\Response\ASPSPsResponse;
use App\Services\EnableBanking\Response\AuthResponse;
use App\Services\EnableBanking\Response\SessionResponse;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

final class UpstreamGateway implements EnableBankingGateway
{
    // Upstream's own default since 2.3.5 (89 days, clear of DST edge cases).
    private const int FALLBACK_CONSENT_SECONDS = 7_689_600;

    public function getSession(string $sessionId): SessionDetails
    {
        $request = new GetSessionRequest($this->url(), $sessionId);
        $request->setTimeOut($this->timeout());

        /** @var SessionDetails */
        return $request->get();
    }

    public function getAccountDetails(string $accountUid): BankAccount
    {
        $request = new GetAccountDetailsRawRequest($this->url(), $accountUid);
        $request->setTimeOut($this->timeout());

        return BankAccount::fromEnableBanking(['uid' => $accountUid, ...$request->fetch()]);
    }

    public function listBanks(string $country): array
    {
        $request = new GetASPSPsRequest($this->url(), $country);
        $request->setTimeOut($this->timeout());

        /** @var ASPSPsResponse $response */
        $response = $request->get();
        $banks    = [];

        /** @var Bank $aspsp */
        foreach ($response->getBanks() as $aspsp) {
            $banks[] = [
                'name'             => $aspsp->name,
                'country'          => '' !== $aspsp->country ? $aspsp->country : $country,
                'logo'             => '' !== $aspsp->logo ? $aspsp->logo : null,
                'max_consent_days' => intdiv($aspsp->maximumConsentValidity, 86_400),
                'psu_types'        => array_values($aspsp->psuTypes),
            ];
        }

        return $banks;
    }

    public function maxConsentSeconds(string $country, string $bank): int
    {
        try {
            $request = new GetASPSPsRequest($this->url(), $country);
            $request->setTimeOut($this->timeout());

            /** @var ASPSPsResponse $response */
            $response = $request->get();

            /** @var Bank $aspsp */
            foreach ($response->getBanks() as $aspsp) {
                if ($aspsp->name === $bank) {
                    return $aspsp->maximumConsentValidity;
                }
            }
        } catch (Throwable $e) {
            Log::warning(sprintf('FireflyX: could not read consent limit for %s/%s: %s', $country, $bank, $e->getMessage()));
        }

        return self::FALLBACK_CONSENT_SECONDS;
    }

    public function startAuthorisation(string $country, string $bank, string $psuType, string $state, string $redirectUrl, Carbon $validUntil): array
    {
        $request = new PostAuthRequest($this->url());
        $request->setTimeOut($this->timeout());
        $request->setAspsp($bank);
        $request->setCountry($country);
        $request->setPsuType($psuType);
        $request->setState($state);
        $request->setRedirectUrl($redirectUrl);
        $request->setValidUntil($validUntil->getTimestamp());

        /** @var AuthResponse $response */
        $response = $request->post();

        return ['url' => $response->url, 'auth_id' => $response->authId];
    }

    public function createSession(string $code): array
    {
        $request = new PostSessionRequest($this->url(), $code);
        $request->setTimeOut($this->timeout());

        /** @var SessionResponse $response */
        $response = $request->post();

        return [
            'session_id'  => $response->sessionId,
            'valid_until' => null === $response->validUntil ? null : Carbon::createFromTimestamp($response->validUntil),
            'accounts'    => array_map(static fn (array $account): BankAccount => BankAccount::fromEnableBanking($account), $response->accounts),
        ];
    }

    public function deleteSession(string $sessionId): void
    {
        $request = new DeleteSessionRequest($this->url(), $sessionId);
        $request->setTimeOut($this->timeout());
        $request->delete();
    }

    private function url(): string
    {
        return (string) config('eb.url');
    }

    private function timeout(): float
    {
        return (float) config('importer.connection.timeout');
    }
}
