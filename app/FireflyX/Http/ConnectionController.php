<?php

/*
 * ConnectionController.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace App\FireflyX\Http;

use App\FireflyX\EnableBanking\EnableBankingGateway;
use App\FireflyX\EnableBanking\SessionRefresher;
use App\FireflyX\Models\Connection;
use App\FireflyX\Models\ConnectionEvent;
use App\FireflyX\Models\ConnectionAccount;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Throwable;

final class ConnectionController extends Controller
{
    public function __construct(
        private readonly SessionRefresher $refresher,
        private readonly EnableBankingGateway $gateway,
    ) {}

    public function index(): JsonResponse
    {
        $staleBefore = Carbon::now()->subMinutes((int) config('fireflyx.refresh_after'));
        // A consent still being given is not a connection yet.
        $connections = Connection::with('accounts')->where('lifecycle', '!=', Connection::LIFECYCLE_PENDING)->orderBy('name')->get()->map(function (Connection $connection) use ($staleBefore): array {
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

    /**
     * Stops importing at once and closes the consent at the bank. The row is
     * soft-deleted, so the name and its import history are kept.
     */
    public function destroy(string $name): Response
    {
        $connection = $this->find($name);
        if (null !== $connection->session_id && '' !== $connection->session_id) {
            try {
                $this->gateway->deleteSession($connection->session_id);
            } catch (Throwable $e) {
                // Best effort: an expired consent is already closed.
                Log::warning(sprintf('FireflyX: could not close the session of %s: %s', $name, $e->getMessage()));
            }
        }
        $connection->delete();
        ConnectionEvent::record($connection, 'removed');
        $rendered = sprintf('%s/%s', rtrim((string) config('fireflyx.import_dir'), '/'), $connection->configFileName());
        if (is_file($rendered)) {
            unlink($rendered);
        }

        return response()->noContent();
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
            'name'          => $connection->name,
            'status'        => $connection->status(),
            'bank'          => ['name' => $connection->bank_name, 'country' => $connection->bank_country, 'logo' => $connection->bank_logo],
            'psu_type'      => $connection->psu_type,
            'expires_at'    => $connection->valid_until?->toW3cString(),
            'days_left'     => $connection->daysLeft(),
            'checked_at'    => $connection->checked_at?->toW3cString(),
            'accounts'      => $connection->accounts->map(static fn (ConnectionAccount $account): array => [
                'id'                  => $account->id,
                'identification_hash' => $account->identification_hash,
                'iban_masked'         => $account->maskedIban(),
                'name'                => $account->name,
                'currency'            => $account->currency,
                'firefly_account_id'  => $account->firefly_account_id,
            ])->all(),
            'last_import'   => null === $connection->last_import_at ? null : [
                'at'      => $connection->last_import_at->toW3cString(),
                'ok'      => $connection->last_import_ok,
                'summary' => $connection->last_import_summary,
            ],
            'last_error'    => $connection->last_error,
            'manual_import' => $connection->manualImports()->latest('id')->first()?->present(),
        ];
    }
}
