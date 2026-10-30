<?php

/*
 * LinkController.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace App\FireflyX\Http;

use App\FireflyX\EnableBanking\BankAccount;
use App\FireflyX\EnableBanking\EnableBankingGateway;
use App\FireflyX\EnableBanking\FlowTrace;
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
use Illuminate\Support\Str;
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
    // Pico's bank connections page.
    private const string DEFAULT_RETURN_PATH = '/bank-connections/list';

    // Where a new connection lands once the bank has given consent: Pico's
    // page for mapping its accounts. Failures go to the request's return_path.
    private const string SETUP_PATH = '/bank-connections/setup';

    // How far back a first import may reach (design doc, open question 3).
    private const int MAX_HISTORY_DAYS = 90;

    public function __construct(
        private readonly EnableBankingGateway $gateway,
        private readonly AccountRemapper $remapper,
    ) {}

    /**
     * POST /api/fireflyx/v1/connections/{name}/reauth {return_path}
     */
    public function reauth(Request $request, string $name): JsonResponse
    {
        $returnPath = (string) $request->input('return_path', self::DEFAULT_RETURN_PATH);
        if (!self::isAllowedReturnPath($returnPath)) {
            return response()->json(['error' => 'return_path must be a path under /bank-connections'], 422);
        }
        $connection = Connection::where('name', $name)->firstOrFail();

        return $this->startConsent(
            $connection,
            PendingAuth::KIND_REAUTH,
            $returnPath,
            $this->gateway->maxConsentSeconds($connection->bank_country, $connection->bank_name)
        );
    }

    /**
     * POST /api/fireflyx/v1/connections {country, bank, psu_type, return_path}
     *
     * Starts the consent for a bank that has no connection yet. The row stays
     * "pending" until the bank sends the user back; a refused or abandoned
     * consent leaves nothing behind.
     */
    public function create(Request $request): JsonResponse
    {
        $country    = strtoupper((string) $request->input('country'));
        $bankName   = (string) $request->input('bank');
        $psuType    = (string) $request->input('psu_type', 'personal');
        $returnPath = (string) $request->input('return_path', self::DEFAULT_RETURN_PATH);
        if (1 !== preg_match('/^[A-Z]{2}$/', $country) || '' === $bankName || !in_array($psuType, ['personal', 'business'], true)) {
            return response()->json(['error' => 'country (two letters), bank and psu_type (personal or business) are required'], 422);
        }
        if (!self::isAllowedReturnPath($returnPath)) {
            return response()->json(['error' => 'return_path must be a path under /bank-connections'], 422);
        }

        try {
            $banks = $this->gateway->listBanks($country);
        } catch (Throwable $e) {
            Log::error(sprintf('FireflyX: could not list the banks of %s: %s', $country, $e->getMessage()));

            return response()->json(['error' => 'Enable Banking could not list the banks.', 'detail' => $e->getMessage()], 502);
        }
        $bank = collect($banks)->firstWhere('name', $bankName);
        if (null === $bank || ([] !== $bank['psu_types'] && !in_array($psuType, $bank['psu_types'], true))) {
            return response()->json(['error' => sprintf('%s does not offer %s accounts in %s', $bankName, $psuType, $country)], 422);
        }

        // Consents nobody came back from.
        Connection::where('lifecycle', Connection::LIFECYCLE_PENDING)
            ->where('created_at', '<', Carbon::now()->subMinutes((int) config('fireflyx.auth_ttl')))
            ->forceDelete();

        $connection = Connection::create([
            'name'         => $this->freeName($bankName, $country),
            'lifecycle'    => Connection::LIFECYCLE_PENDING,
            'bank_name'    => $bank['name'],
            'bank_country' => $country,
            'bank_logo'    => $bank['logo'],
            'psu_type'     => $psuType,
        ]);
        $response   = $this->startConsent($connection, PendingAuth::KIND_CREATE, $returnPath, $bank['max_consent_days'] * 86_400);
        if ($response->getStatusCode() >= 400) {
            $connection->forceDelete();

            return $response;
        }

        return response()->json(['data' => ['name' => $connection->name, ...$response->getData(true)['data']]]);
    }

    /**
     * Asks Enable Banking for the longest consent the bank allows, a day short
     * of it so a clock or DST difference can never push it over
     * (firefly-iii#12553), and remembers the state that authorises the callback.
     */
    private function startConsent(Connection $connection, string $kind, string $returnPath, int $maxSeconds): JsonResponse
    {
        $callback = (string) config('fireflyx.callback_url');
        if ('' === $callback) {
            return response()->json(['error' => 'FIREFLYX_CALLBACK_URL is not configured'], 500);
        }
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
            Log::error(sprintf('FireflyX: could not start the authorisation of %s: %s', $connection->name, $e->getMessage()));

            return response()->json(['error' => 'Enable Banking refused to start the authorisation.', 'detail' => $e->getMessage()], 502);
        }

        PendingAuth::create([
            'state'         => $state,
            'kind'          => $kind,
            'connection_id' => $connection->id,
            'payload'       => ['auth_id' => $auth['auth_id']],
            'return_path'   => $returnPath,
            'expires_at'    => Carbon::now()->addMinutes((int) config('fireflyx.auth_ttl')),
        ]);

        return response()->json(['data' => ['auth_url' => $auth['url']]]);
    }

    /**
     * PUT /api/fireflyx/v1/connections/{name}/accounts {accounts: [{id, firefly_account_id}], import_since}
     *
     * Which Firefly III account each bank account feeds (null = not imported),
     * and how far back the first import reaches. This makes the connection
     * active: it is imported by the next nightly run.
     */
    public function accounts(Request $request, string $name): JsonResponse
    {
        $connection = Connection::with('accounts')->where('name', $name)->firstOrFail();
        if (Connection::LIFECYCLE_PENDING === $connection->lifecycle) {
            return response()->json(['error' => 'The bank has not given consent yet'], 409);
        }
        $targets = [];
        foreach ((array) $request->input('accounts', []) as $entry) {
            $id     = $entry['id'] ?? null;
            $target = $entry['firefly_account_id'] ?? null;
            if (!is_int($id) || !$connection->accounts->contains('id', $id) || (null !== $target && (!is_int($target) || $target < 1))) {
                return response()->json(['error' => 'accounts must be {id, firefly_account_id} of this connection, with a Firefly III account ID or null'], 422);
            }
            $targets[$id] = $target;
        }
        if ([] === array_filter($targets, static fn (?int $target): bool => null !== $target)) {
            return response()->json(['error' => 'Map at least one bank account to a Firefly III account'], 422);
        }

        // Banks only return older history right after consent, and not all of it.
        $since = $request->input('import_since');
        if (null !== $since) {
            try {
                $since = Carbon::createFromFormat('!Y-m-d', (string) $since);
            } catch (Throwable) {
                $since = false;
            }
            if (!$since instanceof Carbon || $since->isFuture() || $since->lessThan(Carbon::today()->subDays(self::MAX_HISTORY_DAYS))) {
                return response()->json(['error' => sprintf('import_since must be a date within the last %d days', self::MAX_HISTORY_DAYS)], 422);
            }
        }

        DB::transaction(static function () use ($connection, $targets, $since): void {
            foreach ($connection->accounts as $account) {
                $account->update(['firefly_account_id' => $targets[$account->id] ?? null]);
            }
            $connection->update(['lifecycle' => Connection::LIFECYCLE_READY, 'import_since' => $since]);
        });

        return response()->json(['data' => ConnectionController::present($connection->refresh()->load('accounts'))]);
    }

    /**
     * GET /fireflyx/eb-callback?state=fx-…&code=… (or &error=…)
     *
     * Public: reached through Caddy without basic auth. The single-use state is
     * the authorisation, and the code is useless without our private key.
     */
    public function callback(Request $request): RedirectResponse
    {
        FlowTrace::event('callback reached', [
            'query'      => FlowTrace::describeCallbackQuery($request->query()),
            'user_agent' => $request->userAgent(),
            'referer'    => $request->headers->get('referer'),
        ]);

        $pending = PendingAuth::consume((string) $request->query('state', ''));
        if (null === $pending) {
            FlowTrace::event('callback state not accepted (unknown, used or expired)');

            // Without a valid state there is no return path to trust: use Pico's page.
            return $this->backToPico(self::DEFAULT_RETURN_PATH, null, 'error', 'invalid_state');
        }
        $connection = $pending->connection;
        if (null === $connection) {
            return $this->backToPico($pending->return_path, null, 'error', 'connection_gone');
        }
        FlowTrace::event('callback matched a started consent', [
            'connection'  => $connection->name,
            'kind'        => $pending->kind,
            'bank'        => $connection->bank_name,
            'psu_type'    => $connection->psu_type,
            'started_ago' => $pending->created_at?->diffForHumans(),
            'auth_id'     => $pending->payload['auth_id'] ?? null,
        ]);
        $code = (string) $request->query('code', '');
        if ('' === $code) {
            // The user cancelled at the bank, or the bank refused. Always logged:
            // Enable Banking says why in error / error_description.
            Log::warning(sprintf(
                'FireflyX: no code came back for %s (error=%s, error_description=%s)',
                $connection->name,
                (string) $request->query('error', ''),
                (string) $request->query('error_description', '')
            ));

            return $this->abandon($pending, $connection, 'bank_declined');
        }

        try {
            $session = $this->gateway->createSession($code);
        } catch (Throwable $e) {
            Log::error(sprintf('FireflyX: could not create a session for %s: %s', $connection->name, $e->getMessage()));

            return $this->abandon($pending, $connection, 'session_failed');
        }

        if ([] === $session['accounts']) {
            // Consent was given but no account was ticked at the bank (OTP banka does this):
            // there is nothing to import, so the consent is closed and the user tries again.
            Log::warning(sprintf('FireflyX: the bank shared no accounts for %s', $connection->name));
            // Look at the session once more before it is closed: does GET /sessions
            // list accounts the POST did not, and what do their details say?
            $this->gateway->diagnoseSession($session['session_id']);
            $this->deleteQuietly($session['session_id']);

            return $this->abandon($pending, $connection, 'no_accounts');
        }

        if (PendingAuth::KIND_CREATE === $pending->kind) {
            return $this->completeCreate($pending, $connection, $session);
        }

        $remap = $this->remapper->remap($connection->accounts, $session['accounts']);
        if (!$remap['complete']) {
            // Keep the working connection as it is; the old session may still be
            // valid. Close the new consent rather than leave it dangling.
            Log::warning(sprintf('FireflyX: re-link of %s could not find: %s', $connection->name, implode(', ', $remap['unmatched'])));
            $this->deleteQuietly($session['session_id']);

            return $this->backToPico($pending->return_path, $connection->name, 'needs_mapping', 'unmatched_accounts');
        }

        $oldSession   = $connection->session_id;
        // A connection that was never mapped (the bank shared no accounts the first time) still has to be.
        $needsMapping = $connection->mappedAccounts()->isEmpty();
        // Session and account uids change together or not at all.
        DB::transaction(static function () use ($connection, $session, $remap, $pending, $needsMapping): void {
            $connection->fill([
                'session_id'  => $session['session_id'],
                'auth_id'     => (string) ($pending->payload['auth_id'] ?? ''),
                'eb_status'   => 'AUTHORIZED',
                'valid_until' => $session['valid_until'],
                'checked_at'  => Carbon::now(),
                'last_error'  => null,
                'relinked_at' => Carbon::now(),
                'lifecycle'   => $needsMapping ? Connection::LIFECYCLE_NEEDS_MAPPING : Connection::LIFECYCLE_READY,
            ])->save();
            $connection->accounts()->delete();
            foreach ($remap['accounts'] as $account) {
                $connection->accounts()->create($account);
            }
        });
        if (null !== $oldSession && $oldSession !== $session['session_id']) {
            $this->deleteQuietly($oldSession);
        }

        return $needsMapping
            ? $this->backToPico(self::SETUP_PATH, $connection->name, 'ok', null)
            : $this->backToPico($pending->return_path, $connection->name, 'ok', null);
    }

    /**
     * @param array{session_id: string, valid_until: ?Carbon, accounts: list<BankAccount>} $session
     */
    private function completeCreate(PendingAuth $pending, Connection $connection, array $session): RedirectResponse
    {
        DB::transaction(static function () use ($connection, $session, $pending): void {
            $connection->fill([
                'session_id'  => $session['session_id'],
                'auth_id'     => (string) ($pending->payload['auth_id'] ?? ''),
                'eb_status'   => 'AUTHORIZED',
                'valid_until' => $session['valid_until'],
                'checked_at'  => Carbon::now(),
                'lifecycle'   => Connection::LIFECYCLE_NEEDS_MAPPING,
            ])->save();
            foreach ($session['accounts'] as $account) {
                $connection->accounts()->create([
                    'uid'                 => $account->uid,
                    'identification_hash' => '' !== $account->identificationHash ? $account->identificationHash : null,
                    'iban'                => '' !== $account->iban ? $account->iban : null,
                    'name'                => $account->name,
                    'currency'            => '' !== $account->currency ? $account->currency : null,
                ]);
            }
        });

        return $this->backToPico(self::SETUP_PATH, $connection->name, 'ok', null);
    }

    /**
     * The consent failed. A new connection has nothing worth keeping, so it
     * goes; a re-linked one carries on with its old session.
     */
    private function abandon(PendingAuth $pending, Connection $connection, string $reason): RedirectResponse
    {
        if (PendingAuth::KIND_CREATE === $pending->kind) {
            $connection->forceDelete();
        }

        return $this->backToPico($pending->return_path, $connection->name, 'error', $reason);
    }

    private function freeName(string $bank, string $country): string
    {
        $base = Str::limit(Str::slug($bank) ?: strtolower($country), 56, '');
        $name = $base;
        for ($i = 2; Connection::withTrashed()->where('name', $name)->exists(); ++$i) {
            $name = sprintf('%s-%d', $base, $i);
        }

        return $name;
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
