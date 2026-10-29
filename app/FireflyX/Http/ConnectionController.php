<?php

/*
 * ConnectionController.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace App\FireflyX\Http;

use App\FireflyX\EnableBanking\SessionRefresher;
use App\FireflyX\Models\Connection;
use App\FireflyX\Models\ConnectionAccount;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

final class ConnectionController extends Controller
{
    public function __construct(private readonly SessionRefresher $refresher) {}

    public function index(): JsonResponse
    {
        $staleBefore = Carbon::now()->subMinutes((int) config('fireflyx.refresh_after'));
        $connections = Connection::with('accounts')->orderBy('name')->get()->map(function (Connection $connection) use ($staleBefore): array {
            if (!$connection->checked_at instanceof Carbon || $connection->checked_at->lessThan($staleBefore)) {
                $connection = $this->refresher->refresh($connection);
            }

            return self::present($connection);
        });

        return response()->json(['data' => $connections->all()]);
    }

    public function show(string $name): JsonResponse
    {
        return response()->json(['data' => self::present($this->find($name))]);
    }

    public function refresh(string $name): JsonResponse
    {
        return response()->json(['data' => self::present($this->refresher->refresh($this->find($name)))]);
    }

    private function find(string $name): Connection
    {
        return Connection::with('accounts')->where('name', $name)->firstOrFail();
    }

    /**
     * The contract Pico reads (docs/design/bank-link.md §7.1 in FireflyX).
     * Session and auth IDs never leave the importer.
     */
    public static function present(Connection $connection): array
    {
        return [
            'name'        => $connection->name,
            'status'      => $connection->status(),
            'bank'        => ['name' => $connection->bank_name, 'country' => $connection->bank_country, 'logo' => $connection->bank_logo],
            'psu_type'    => $connection->psu_type,
            'expires_at'  => $connection->valid_until?->toW3cString(),
            'days_left'   => $connection->daysLeft(),
            'checked_at'  => $connection->checked_at?->toW3cString(),
            'accounts'    => $connection->accounts->map(static fn (ConnectionAccount $account): array => [
                'identification_hash' => $account->identification_hash,
                'iban_masked'         => $account->maskedIban(),
                'name'                => $account->name,
                'currency'            => $account->currency,
                'firefly_account_id'  => $account->firefly_account_id,
            ])->all(),
            'last_import' => null === $connection->last_import_at ? null : [
                'at'      => $connection->last_import_at->toW3cString(),
                'ok'      => $connection->last_import_ok,
                'summary' => $connection->last_import_summary,
            ],
            'last_error'  => $connection->last_error,
        ];
    }
}
