<?php

/*
 * RelinkTest.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace Tests\Feature\FireflyX;

use App\FireflyX\EnableBanking\EnableBankingGateway;
use App\FireflyX\Models\Connection;
use App\FireflyX\Models\PendingAuth;
use Carbon\Carbon;
use Tests\FireflyXTestCase;

/**
 * Re-authenticate from Pico: reauth → bank → /fireflyx/eb-callback → Pico.
 */
final class RelinkTest extends FireflyXTestCase
{
    private FakeGateway $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bank = new FakeGateway();
        $this->app->instance(EnableBankingGateway::class, $this->bank);
        config([
            'fireflyx.callback_url' => 'https://import.example.test/eb-callback',
            'fireflyx.pico_url'     => 'https://pico.example.test',
        ]);
    }

    public function testReauthStartsAConsentForTheLongestTheBankAllows(): void
    {
        $this->nlb();

        $response = $this->postJson('/api/fireflyx/v1/connections/nlb/reauth', ['return_path' => '/bank-connections'], $this->auth());

        $response->assertOk()->assertJsonPath('data.auth_url', fn (string $url): bool => str_starts_with($url, 'https://tilisy.example/auth?state=fx-'));
        $started = $this->bank->called('startAuthorisation')[0][1];
        $this->assertSame(['SI', 'NLB', 'personal', 'https://import.example.test/eb-callback'], [$started['country'], $started['bank'], $started['psuType'], $started['redirectUrl']]);
        $this->assertMatchesRegularExpression(PendingAuth::STATE_PATTERN, $started['state']);
        // Seconds, not calendar days: across a DST change those differ by an
        // hour, which is exactly how upstream's 90 days overshot the limit.
        $this->assertEqualsWithDelta(time() + 179 * 86_400, $started['validUntil']->getTimestamp(), 5, 'bank maximum minus a day');
        $this->assertSame(180, Connection::where('name', 'nlb')->first()->max_consent_days);
    }

    public function testReauthRefusesAnythingButAPicoPath(): void
    {
        $this->nlb();

        foreach (['https://evil.example', '//evil.example', '/bank-connections/../x', '/elsewhere'] as $path) {
            $this->postJson('/api/fireflyx/v1/connections/nlb/reauth', ['return_path' => $path], $this->auth())->assertStatus(422);
        }
        $this->postJson('/api/fireflyx/v1/connections/nlb/reauth', [], [])->assertUnauthorized();
        $this->assertSame([], $this->bank->called('startAuthorisation'));
    }

    public function testCallbackSwapsSessionAndUidsAndSendsTheUserBackToPico(): void
    {
        $this->nlb();
        $state                   = $this->startReauth();
        $this->bank->nextSession = ['session_id' => 'session-new', 'accounts' => [
            // Same account, new uid: matched by identification_hash.
            ['uid' => 'uid-new', 'identification_hash' => 'hash-1', 'account_id' => ['iban' => 'SI56191000000123438'], 'currency' => 'EUR'],
        ]];

        $this->get('/fireflyx/eb-callback?state='.$state.'&code=the-code')
            ->assertRedirect('https://pico.example.test/bank-connections?connection=nlb&result=ok');

        $connection = Connection::with('accounts')->where('name', 'nlb')->first();
        $this->assertSame('session-new', $connection->session_id);
        $this->assertSame('new-auth-id', $connection->auth_id);
        $this->assertSame(['uid-new'], $connection->accounts->pluck('uid')->all());
        $this->assertSame(1, $connection->accounts->first()->firefly_account_id);
        $this->assertSame('active', $connection->status());
        $this->assertSame([['deleteSession', 'session-old']], $this->bank->called('deleteSession'), 'the old consent is closed');

        // The state is single use: a replayed callback does nothing.
        $this->get('/fireflyx/eb-callback?state='.$state.'&code=the-code')
            ->assertRedirect('https://pico.example.test/bank-connections/list?result=error&reason=invalid_state');
        $this->assertCount(1, $this->bank->called('createSession'));
    }

    public function testFallsBackToTheIbanWhenTheHashIsMissing(): void
    {
        $this->nlb();
        $state                   = $this->startReauth();
        $this->bank->nextSession = ['session_id' => 'session-new', 'accounts' => [
            ['uid' => 'uid-new', 'account_id' => ['iban' => 'SI56191000000123438']],
        ]];

        $this->get('/fireflyx/eb-callback?state='.$state.'&code=c')->assertRedirect('https://pico.example.test/bank-connections?connection=nlb&result=ok');
        $this->assertSame('hash-1', Connection::with('accounts')->where('name', 'nlb')->first()->accounts->first()->identification_hash);
    }

    public function testUnmatchedAccountsLeaveTheWorkingConnectionAlone(): void
    {
        $this->nlb();
        $state                   = $this->startReauth();
        $this->bank->nextSession = ['session_id' => 'session-new', 'accounts' => [
            ['uid' => 'uid-other', 'identification_hash' => 'hash-other', 'account_id' => ['iban' => 'SI56000000000000000']],
        ]];

        $this->get('/fireflyx/eb-callback?state='.$state.'&code=c')
            ->assertRedirect('https://pico.example.test/bank-connections?connection=nlb&result=needs_mapping&reason=unmatched_accounts');

        $this->assertSame('session-old', Connection::where('name', 'nlb')->first()->session_id);
        $this->assertSame([['deleteSession', 'session-new']], $this->bank->called('deleteSession'), 'the unused new consent is closed');
    }

    public function testDeclinedOrExpiredConsentChangesNothing(): void
    {
        $this->nlb();
        $declined = $this->startReauth();
        $this->get('/fireflyx/eb-callback?state='.$declined.'&error=access_denied')
            ->assertRedirect('https://pico.example.test/bank-connections?connection=nlb&result=error&reason=bank_declined');

        $expired = $this->startReauth();
        PendingAuth::whereKey($expired)->update(['expires_at' => Carbon::now()->subMinute()]);
        $this->get('/fireflyx/eb-callback?state='.$expired.'&code=c')
            ->assertRedirect('https://pico.example.test/bank-connections/list?result=error&reason=invalid_state');

        $this->get('/fireflyx/eb-callback?state=5f0c2a9e-1111-4222-8333-944455556666&code=c')
            ->assertRedirect('https://pico.example.test/bank-connections/list?result=error&reason=invalid_state');

        $this->assertSame([], $this->bank->called('createSession'));
        $this->assertSame('session-old', Connection::where('name', 'nlb')->first()->session_id);
    }

    public function testFailedSessionExchangeIsReported(): void
    {
        $this->nlb();
        $state                         = $this->startReauth();
        $this->bank->failCreateSession = true;

        $this->get('/fireflyx/eb-callback?state='.$state.'&code=c')
            ->assertRedirect('https://pico.example.test/bank-connections?connection=nlb&result=error&reason=session_failed');
        $this->assertSame('session-old', Connection::where('name', 'nlb')->first()->session_id);
    }

    public function testRemoveStopsImportingAndClosesTheConsent(): void
    {
        $this->nlb();
        $this->artisan('fireflyx:render')->assertSuccessful();
        $this->assertFileExists($this->importDir.'/nlb.json');

        $this->deleteJson('/api/fireflyx/v1/connections/nlb', [], $this->auth())->assertNoContent();

        $this->assertFileDoesNotExist($this->importDir.'/nlb.json');
        $this->assertSame([['deleteSession', 'session-old']], $this->bank->called('deleteSession'));
        $this->assertNull(Connection::where('name', 'nlb')->first());
        $this->assertNotNull(Connection::withTrashed()->where('name', 'nlb')->first(), 'soft-deleted, history kept');
        $this->getJson('/api/fireflyx/v1/connections/nlb', $this->auth())->assertNotFound();
    }

    public function testRefreshRecordsTheHashAndDetailsForLaterRelinks(): void
    {
        $this->nlb(['identification_hash' => null, 'iban' => null]);
        $this->bank->sessions['session-old']    = [
            'status'        => 'AUTHORIZED',
            'access'        => ['valid_until' => Carbon::now()->addDays(20)->addHour()->toW3cString()],
            'accounts_data' => [['uid' => 'uid-old', 'identification_hash' => 'hash-1']],
        ];
        $this->bank->accountDetails['uid-old'] = ['uid' => 'uid-old', 'account_id' => ['iban' => 'SI56191000000123438'], 'name' => 'Osebni račun', 'currency' => 'EUR'];

        $this->artisan('fireflyx:connections:refresh', ['--warn-at' => '20'])
            ->expectsOutputToContain('nlb: active, 20 day(s) left')
            ->assertFailed();

        $account = Connection::with('accounts')->where('name', 'nlb')->first()->accounts->first();
        $this->assertSame(['hash-1', 'SI56191000000123438', 'EUR'], [$account->identification_hash, $account->iban, $account->currency]);

        // Details carry the hash too; it must stay Enable Banking's raw value,
        // the same one GET /sessions returns, or a re-link could never match.
        $account->update(['iban' => null]);
        $this->bank->accountDetails['uid-old']['identification_hash'] = 'hash-1';
        $this->artisan('fireflyx:connections:refresh', ['--warn-at' => '0']);
        $this->assertSame('hash-1', $account->fresh()->identification_hash);
    }

    private function startReauth(): string
    {
        $this->postJson('/api/fireflyx/v1/connections/nlb/reauth', ['return_path' => '/bank-connections'], $this->auth())->assertOk();

        return (string) PendingAuth::latest('created_at')->latest('state')->whereNull('used_at')->first()?->state
            ?: $this->bank->called('startAuthorisation')[count($this->bank->called('startAuthorisation')) - 1][1]['state'];
    }

    private function nlb(array $account = []): Connection
    {
        $connection = Connection::create([
            'name'         => 'nlb',
            'lifecycle'    => Connection::LIFECYCLE_READY,
            'bank_name'    => 'NLB',
            'bank_country' => 'SI',
            'session_id'   => 'session-old',
            'auth_id'      => 'auth-old',
            'eb_status'    => 'AUTHORIZED',
            'valid_until'  => Carbon::now()->addDays(60),
            'checked_at'   => Carbon::now(),
        ]);
        $connection->accounts()->create([
            'uid'                 => 'uid-old',
            'identification_hash' => 'hash-1',
            'iban'                => 'SI56191000000123438',
            'firefly_account_id'  => 1,
            ...$account,
        ]);

        return $connection;
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.str_repeat('t', 40)];
    }
}
