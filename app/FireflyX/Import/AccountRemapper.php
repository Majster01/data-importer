<?php

/*
 * AccountRemapper.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace App\FireflyX\Import;

use App\FireflyX\Models\ConnectionAccount;
use App\FireflyX\EnableBanking\BankAccount;
use Illuminate\Support\Collection;

/**
 * After a re-link every account has a new uid (uids die with their session).
 * Carry each account's Firefly III target over by identification_hash, which
 * Enable Banking documents as the key for matching accounts between sessions,
 * falling back to the IBAN.
 */
final class AccountRemapper
{
    /**
     * @param Collection<int, ConnectionAccount> $old
     * @param list<BankAccount>                  $new
     *
     * @return array{complete: bool, accounts: list<array<string, mixed>>, unmatched: list<string>}
     */
    public function remap(Collection $old, array $new): array
    {
        $accounts = [];
        $matched  = [];

        foreach ($new as $account) {
            $previous = $this->find($old, $account);
            if (null !== $previous) {
                $matched[] = $previous->id;
            }
            $accounts[] = [
                'uid'                 => $account->uid,
                'identification_hash' => '' !== $account->identificationHash ? $account->identificationHash : $previous?->identification_hash,
                'iban'                => '' !== $account->iban ? $account->iban : $previous?->iban,
                'name'                => $account->name,
                'currency'            => '' !== $account->currency ? $account->currency : $previous?->currency,
                // A new account the bank now shares is not imported until mapped.
                'firefly_account_id'  => $previous?->firefly_account_id,
            ];
        }

        // Only accounts that were being imported must be found again.
        $unmatched = $old
            ->filter(static fn (ConnectionAccount $account): bool => null !== $account->firefly_account_id && !in_array($account->id, $matched, true))
            ->map(static fn (ConnectionAccount $account): string => $account->name ?? $account->maskedIban() ?? $account->uid)
            ->values()
            ->all();

        return ['complete' => [] === $unmatched, 'accounts' => $accounts, 'unmatched' => $unmatched];
    }

    /**
     * @param Collection<int, ConnectionAccount> $old
     */
    private function find(Collection $old, BankAccount $account): ?ConnectionAccount
    {
        $hash = $account->identificationHash;
        if ('' !== $hash) {
            $byHash = $old->first(static fn (ConnectionAccount $previous): bool => $previous->identification_hash === $hash);
            if (null !== $byHash) {
                return $byHash;
            }
        }
        $iban = $account->iban;
        if ('' !== $iban) {
            return $old->first(static fn (ConnectionAccount $previous): bool => $previous->iban === $iban);
        }

        return null;
    }
}
