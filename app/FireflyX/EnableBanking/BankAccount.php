<?php

/*
 * BankAccount.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace App\FireflyX\EnableBanking;

use App\Services\EnableBanking\Model\Account;

/**
 * An account as Enable Banking returned it.
 *
 * Upstream's Account is used to parse the IBAN, name and currency, but not
 * the identification_hash: Account::fromArray() re-hashes it, and when it is
 * missing invents one from the whole account, uid included, so it would change
 * with every session. Re-linking matches on Enable Banking's own value, kept
 * raw here and everywhere it is stored ('' when the bank did not send one).
 */
final readonly class BankAccount
{
    public function __construct(
        public string $uid,
        public string $identificationHash,
        public string $iban,
        public string $name,
        public string $currency,
    ) {}

    public static function fromEnableBanking(array $raw): self
    {
        $parsed = Account::fromArray($raw);

        return new self(
            uid: $parsed->getUid(),
            identificationHash: (string) ($raw['identification_hash'] ?? ''),
            iban: $parsed->getIban(),
            name: $parsed->getFullName(),
            currency: $parsed->getCurrency(),
        );
    }
}
