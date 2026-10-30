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

        // PostAuthRequest builds the body itself: access = {valid_until} only,
        // aspsp = {name, country}, state, redirect_url, psu_type.
        FlowTrace::event('POST /auth request', [
            'aspsp'        => $bank,
            'country'      => $country,
            'psu_type'     => $psuType,
            'redirect_url' => $redirectUrl,
            'valid_until'  => $validUntil->toIso8601String(),
            'state'        => substr($state, 0, 6).'…',
        ]);

        /** @var AuthResponse $response */
        $response = $request->post();

        FlowTrace::event('POST /auth response', [
            'authorization_id' => $response->authId,
            'url'              => FlowTrace::describeUrl($response->url),
        ]);

        return ['url' => $response->url, 'auth_id' => $response->authId];
    }

    public function createSession(string $code): array
    {
        $request = new PostSessionRawRequest($this->url(), $code);
        $request->setTimeOut($this->timeout());

        $json = $request->fetch();
        FlowTrace::event('POST /sessions response', FlowTrace::describeSession($json));

        $response = SessionResponse::fromArray($json);

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

    public function diagnoseSession(string $sessionId): void
    {
        if (!FlowTrace::enabled()) {
            return;
        }

        $uids = [];

        try {
            $request = new GetSessionRequest($this->url(), $sessionId);
            $request->setTimeOut($this->timeout());
            $json = $request->fetch();
            FlowTrace::event('GET /sessions/{id} (second look)', FlowTrace::describeSession($json));

            // GET /sessions lists uids in "accounts" and in "accounts_data".
            foreach ((array) ($json['accounts'] ?? []) as $account) {
                $uids[] = is_array($account) ? ($account['uid'] ?? null) : $account;
            }
            foreach ((array) ($json['accounts_data'] ?? []) as $account) {
                $uids[] = $account['uid'] ?? null;
            }
        } catch (Throwable $e) {
            FlowTrace::event('GET /sessions/{id} failed', ['error' => $e->getMessage()]);
        }

        foreach (array_unique(array_filter($uids, 'is_string')) as $uid) {
            try {
                $details = new GetAccountDetailsRawRequest($this->url(), $uid);
                $details->setTimeOut($this->timeout());
                $raw = $details->fetch();
                FlowTrace::event('GET /accounts/{uid}/details', [
                    'uid'     => $uid,
                    'account' => FlowTrace::describeAccount($raw),
                    ...(FlowTrace::raw() ? ['raw' => $raw] : []),
                ]);
            } catch (Throwable $e) {
                FlowTrace::event('GET /accounts/{uid}/details failed', ['uid' => $uid, 'error' => $e->getMessage()]);
            }
        }
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
