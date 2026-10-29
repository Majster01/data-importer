<?php

/*
 * LinkController.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace App\FireflyX\Http;

use App\FireflyX\EnableBanking\EnableBankingGateway;
use App\FireflyX\Import\AccountRemapper;
use App\FireflyX\Models\Connection;
use App\FireflyX\Models\PendingAuth;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Re-authorising a connection from Pico.
 *
 * Enable Banking only redirects to registered URLs, and the one registered is
 * upstream's /eb-callback. Our state starts with "fx-", upstream's is a UUID,
 * so Caddy rewrites our callbacks to /fireflyx/eb-callback and upstream never
 * sees them.
 */
final class LinkController extends Controller
{
    public function __construct(
        private readonly EnableBankingGateway $gateway,
        private readonly AccountRemapper $remapper,
    ) {}

    /**
     * POST /api/fireflyx/v1/connections/{name}/reauth {return_path}
     */
    public function reauth(Request $request, string $name): JsonResponse
    {
        $returnPath = (string) $request->input('return_path', '/bank-connections');
        if (!self::isAllowedReturnPath($returnPath)) {
            return response()->json(['error' => 'return_path must be a path under /bank-connections'], 422);
        }
        $connection = Connection::where('name', $name)->firstOrFail();
        $callback   = (string) config('fireflyx.callback_url');
        if ('' === $callback) {
            return response()->json(['error' => 'FIREFLYX_CALLBACK_URL is not configured'], 500);
        }

        // Ask for the longest consent the bank allows, a day short of it so a
        // clock or DST difference can never push it over (firefly-iii#12553).
        $maxSeconds                   = $this->gateway->maxConsentSeconds($connection->bank_country, $connection->bank_name);
        $connection->max_consent_days = intdiv($maxSeconds, 86_400);
        $connection->save();
        $validUntil                   = Carbon::now()->addSeconds(max(86_400, $maxSeconds - 86_400));

        $state = PendingAuth::newState();

        try {
            $auth = $this->gateway->startAuthorisation(
                $connection->bank_country,
                $connection->bank_name,
                $connection->psu_type,
                $state,
                $callback,
                $validUntil
            );
        } catch (Throwable $e) {
            Log::error(sprintf('FireflyX: could not start re-authorisation of %s: %s', $name, $e->getMessage()));

            return response()->json(['error' => 'Enable Banking refused to start the authorisation.', 'detail' => $e->getMessage()], 502);
        }

        PendingAuth::create([
            'state'         => $state,
            'kind'          => PendingAuth::KIND_REAUTH,
            'connection_id' => $connection->id,
            'payload'       => ['auth_id' => $auth['auth_id']],
            'return_path'   => $returnPath,
            'expires_at'    => Carbon::now()->addMinutes((int) config('fireflyx.auth_ttl')),
        ]);

        return response()->json(['data' => ['auth_url' => $auth['url']]]);
    }

    /**
     * GET /fireflyx/eb-callback?state=fx-…&code=… (or &error=…)
     *
     * Public: reached through Caddy without basic auth. The single-use state is
     * the authorisation, and the code is useless without our private key.
     */
    public function callback(Request $request): RedirectResponse
    {
        $pending = PendingAuth::consume((string) $request->query('state', ''));
        if (null === $pending) {
            return $this->backToPico('/bank-connections', null, 'error', 'invalid_state');
        }
        $connection = $pending->connection;
        if (null === $connection) {
            return $this->backToPico($pending->return_path, null, 'error', 'connection_gone');
        }
        $code = (string) $request->query('code', '');
        if ('' === $code) {
            // The user cancelled at the bank, or the bank refused.
            return $this->backToPico($pending->return_path, $connection->name, 'error', 'bank_declined');
        }

        try {
            $session = $this->gateway->createSession($code);
        } catch (Throwable $e) {
            Log::error(sprintf('FireflyX: could not create a session for %s: %s', $connection->name, $e->getMessage()));

            return $this->backToPico($pending->return_path, $connection->name, 'error', 'session_failed');
        }

        $remap = $this->remapper->remap($connection->accounts, $session['accounts']);
        if (!$remap['complete']) {
            // Keep the working connection as it is; the old session may still be
            // valid. Close the new consent rather than leave it dangling.
            Log::warning(sprintf('FireflyX: re-link of %s could not find: %s', $connection->name, implode(', ', $remap['unmatched'])));
            $this->deleteQuietly($session['session_id']);

            return $this->backToPico($pending->return_path, $connection->name, 'needs_mapping', 'unmatched_accounts');
        }

        $oldSession = $connection->session_id;
        // Session and account uids change together or not at all.
        DB::transaction(static function () use ($connection, $session, $remap, $pending): void {
            $connection->fill([
                'session_id'  => $session['session_id'],
                'auth_id'     => (string) ($pending->payload['auth_id'] ?? ''),
                'eb_status'   => 'AUTHORIZED',
                'valid_until' => $session['valid_until'],
                'checked_at'  => Carbon::now(),
                'last_error'  => null,
                'relinked_at' => Carbon::now(),
                'lifecycle'   => Connection::LIFECYCLE_READY,
            ])->save();
            $connection->accounts()->delete();
            foreach ($remap['accounts'] as $account) {
                $connection->accounts()->create($account);
            }
        });
        if (null !== $oldSession && $oldSession !== $session['session_id']) {
            $this->deleteQuietly($oldSession);
        }

        return $this->backToPico($pending->return_path, $connection->name, 'ok', null);
    }

    public static function isAllowedReturnPath(string $path): bool
    {
        // A path only, so the callback can never become an open redirect.
        return 1 === preg_match('#^/bank-connections(/[A-Za-z0-9/_-]*)?$#', $path);
    }

    private function backToPico(string $path, ?string $name, string $result, ?string $reason): RedirectResponse
    {
        $query = array_filter(['connection' => $name, 'result' => $result, 'reason' => $reason], static fn (?string $value): bool => null !== $value);

        return redirect()->away(sprintf('%s%s?%s', rtrim((string) config('fireflyx.pico_url'), '/'), $path, http_build_query($query)));
    }

    private function deleteQuietly(string $sessionId): void
    {
        try {
            $this->gateway->deleteSession($sessionId);
        } catch (Throwable $e) {
            Log::warning(sprintf('FireflyX: could not close an Enable Banking session: %s', $e->getMessage()));
        }
    }
}
