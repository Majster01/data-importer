<?php

/*
 * SessionDetails.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace App\FireflyX\EnableBanking;

use App\Services\Shared\Response\Response;
use Carbon\Carbon;

/**
 * GET /sessions/{id}. Upstream reads the same endpoint for its accounts only;
 * we need the consent status and expiry, and each account's
 * identification_hash, the one key that survives a re-authorisation.
 */
final class SessionDetails extends Response
{
    public string  $status     = '';
    public ?Carbon $validUntil = null;

    /** @var array<string, string> uid => identification_hash */
    public array $identificationHashes = [];

    public function __construct(array $data)
    {
        $this->status = (string) ($data['status'] ?? '');

        $validUntil = $data['access']['valid_until'] ?? null;
        if (is_string($validUntil) && '' !== $validUntil) {
            $this->validUntil = Carbon::parse($validUntil);
        }

        foreach ($data['accounts_data'] ?? [] as $account) {
            $uid = (string) ($account['uid'] ?? '');
            if ('' !== $uid) {
                $this->identificationHashes[$uid] = (string) ($account['identification_hash'] ?? '');
            }
        }
    }
}
