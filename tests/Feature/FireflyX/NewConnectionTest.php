<?php

/*
 * NewConnectionTest.php
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
 * Add a bank from Pico: banks → create → bank → /fireflyx/eb-callback → map accounts.
 */
final class NewConnectionTest extends FireflyXTestCase
{
    private FakeGateway $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bank        = new FakeGateway();
        $this->bank->banks = ['SI' => [
            ['name' => 'NLB', 'country' => 'SI', 'logo' => 'https://logos.example/nlb.png', 'max_consent_days' => 180, 'psu_types' => ['personal', 'business']],
            ['name' => 'Revolut', 'country' => 'SI', 'logo' => null, 'max_consent_days' => 90, 'psu_types' => ['personal']],
        ]];
        $this->app->instance(EnableBankingGateway::class, $this->bank);
        config([
            'fireflyx.callback_url' => 'https://import.example.test/eb-callback',
            'fireflyx.pico_url'     => 'https://pico.example.test',
        ]);
    }

    public function testListsTheBanksOfACountry(): void
    {
        $this->getJson('/api/fireflyx/v1/banks?country=si', $this->auth())
            ->assertOk()
            ->assertJsonPath('data.0.name', 'NLB')
            ->assertJsonPath('data.1.psu_types', ['personal']);
        $this->getJson('/api/fireflyx/v1/banks?country=Slovenia', $this->auth())->assertStatus(422);
        $this->getJson('/api/fireflyx/v1/banks?country=SI', [])->assertUnauthorized();
    }

    public function testCreateStartsAConsentWithoutListingTheConnectionYet(): void
    {
        $response = $this->create();

        $response->assertOk()->assertJsonPath('data.name', 'nlb');
        $started = $this->bank->called('startAuthorisation')[0][1];
        $this->assertSame(['SI', 'NLB', 'personal'], [$started['country'], $started['bank'], $started['psuType']]);
        $this->assertEqualsWithDelta(time() + 179 * 86_400, $started['validUntil']->getTimestamp(), 5);
        $connection = Connection::where('name', 'nlb')->first();
        $this->assertSame([Connection::LIFECYCLE_PENDING, 'https://logos.example/nlb.png', 180], [$connection->lifecycle, $connection->bank_logo, $connection->max_consent_days]);
        $this->getJson('/api/fireflyx/v1/connections', $this->auth())->assertOk()->assertJsonCount(0, 'data');
    }

    public function testCreateRefusesWhatTheBankDoesNotOffer(): void
    {
        foreach ([
            ['country' => 'SI', 'bank' => 'Nope', 'psu_type' => 'personal'],
            ['country' => 'SI', 'bank' => 'Revolut', 'psu_type' => 'business'],
            ['country' => 'SI', 'bank' => 'NLB', 'psu_type' => 'corporate'],
            ['country' => 'Slovenia', 'bank' => 'NLB', 'psu_type' => 'personal'],
            ['country' => 'SI', 'bank' => 'NLB', 'return_path' => 'https://evil.example'],
        ] as $body) {
            $this->postJson('/api/fireflyx/v1/connections', $body, $this->auth())->assertStatus(422);
        }
        $this->assertSame([], $this->bank->called('startAuthorisation'));
        $this->assertSame(0, Connection::count());
    }

    public function testCreateLeavesNothingBehindWhenTheBankRefusesToStart(): void
    {
        $this->bank->failStartAuth = true;

        $this->create()->assertStatus(502);

        $this->assertSame(0, Connection::withTrashed()->count());
    }

    public function testASecondConnectionToTheSameBankGetsItsOwnName(): void
    {
        $this->create()->assertOk()->assertJsonPath('data.name', 'nlb');
        Connection::where('name', 'nlb')->first()->delete(); // soft: the name stays taken
        $this->create()->assertOk()->assertJsonPath('data.name', 'nlb-2');
    }

    public function testCreateClearsConsentsNobodyCameBackFor(): void
    {
        $this->create();
        Connection::where('name', 'nlb')->update(['created_at' => Carbon::now()->subHour()]);

        $this->create()->assertOk()->assertJsonPath('data.name', 'nlb');
        $this->assertSame(1, Connection::withTrashed()->count());
    }

    public function testCallbackStoresTheAccountsAndSendsTheUserToTheSetupPage(): void
    {
        $this->create();
        $this->bank->nextSession = ['session_id' => 'session-new', 'accounts' => [
            ['uid' => 'uid-1', 'identification_hash' => 'hash-1', 'account_id' => ['iban' => 'SI56191000000123438'], 'name' => 'Osebni račun', 'currency' => 'EUR'],
            ['uid' => 'uid-2', 'account_id' => ['iban' => 'SI56191000000999999'], 'currency' => 'EUR'],
        ]];

        $this->get('/fireflyx/eb-callback?state='.$this->state().'&code=the-code')
            ->assertRedirect('https://pico.example.test/bank-connections/setup?connection=nlb&result=ok');

        $connection = Connection::with('accounts')->where('name', 'nlb')->first();
        $this->assertSame([Connection::LIFECYCLE_NEEDS_MAPPING, 'session-new', 'new-auth-id'], [$connection->lifecycle, $connection->session_id, $connection->auth_id]);
        $this->assertSame(['uid-1', 'uid-2'], $connection->accounts->pluck('uid')->all());
        $this->assertSame([null, null], $connection->accounts->pluck('firefly_account_id')->all());
        $this->assertSame('needs_mapping', $connection->status());
        $this->assertFalse($connection->isImportable(), 'nothing is imported before it is mapped');

        $this->getJson('/api/fireflyx/v1/connections/nlb', $this->auth())
            ->assertOk()
            ->assertJsonPath('data.status', 'needs_mapping')
            ->assertJsonPath('data.accounts.0.id', $connection->accounts[0]->id);
    }

    public function testADeclinedOrFailedConsentRemovesThePendingConnection(): void
    {
        $this->create();
        $this->get('/fireflyx/eb-callback?state='.$this->state().'&error=access_denied')
            ->assertRedirect('https://pico.example.test/bank-connections/list?connection=nlb&result=error&reason=bank_declined');
        $this->assertSame(0, Connection::withTrashed()->count());

        $this->create();
        $this->bank->failCreateSession = true;
        $this->get('/fireflyx/eb-callback?state='.$this->state().'&code=c')
            ->assertRedirect('https://pico.example.test/bank-connections/list?connection=nlb&result=error&reason=session_failed');
        $this->assertSame(0, Connection::withTrashed()->count());
    }

    public function testAConsentThatSharesNoAccountsIsClosedAndLeavesNothingBehind(): void
    {
        $this->create();
        $this->bank->nextSession = ['session_id' => 'session-empty', 'accounts' => []];

        $this->get('/fireflyx/eb-callback?state='.$this->state().'&code=c')
            ->assertRedirect('https://pico.example.test/bank-connections/list?connection=nlb&result=error&reason=no_accounts');

        $this->assertSame([['deleteSession', 'session-empty']], $this->bank->called('deleteSession'), 'the useless consent is closed');
        $this->assertSame(0, Connection::withTrashed()->count());
    }

    public function testReauthenticatingAnUnmappedConnectionGoesToTheSetupPage(): void
    {
        // The state OTP banka left behind: consent, no accounts, waiting for a mapping.
        Connection::create([
            'name' => 'otp-banka', 'lifecycle' => Connection::LIFECYCLE_NEEDS_MAPPING, 'bank_name' => 'OTP banka', 'bank_country' => 'SI',
            'session_id' => 'session-old', 'auth_id' => 'auth-old', 'eb_status' => 'AUTHORIZED', 'valid_until' => Carbon::now()->addDays(60), 'checked_at' => Carbon::now(),
        ]);
        $this->postJson('/api/fireflyx/v1/connections/otp-banka/reauth', ['return_path' => '/bank-connections/list'], $this->auth())->assertOk();

        // Still nothing shared: the old connection stays as it is.
        $this->bank->nextSession = ['session_id' => 'session-empty', 'accounts' => []];
        $this->get('/fireflyx/eb-callback?state='.$this->state().'&code=c')
            ->assertRedirect('https://pico.example.test/bank-connections/list?connection=otp-banka&result=error&reason=no_accounts');
        $this->assertSame('session-old', Connection::where('name', 'otp-banka')->first()->session_id);

        // The account is ticked this time.
        $this->postJson('/api/fireflyx/v1/connections/otp-banka/reauth', ['return_path' => '/bank-connections/list'], $this->auth())->assertOk();
        $this->bank->nextSession = ['session_id' => 'session-new', 'accounts' => [
            ['uid' => 'uid-1', 'identification_hash' => 'hash-1', 'account_id' => ['iban' => 'SI56191000000123438'], 'currency' => 'EUR'],
        ]];
        $this->get('/fireflyx/eb-callback?state='.$this->state().'&code=c')
            ->assertRedirect('https://pico.example.test/bank-connections/setup?connection=otp-banka&result=ok');

        $connection = Connection::with('accounts')->where('name', 'otp-banka')->first();
        $this->assertSame(['needs_mapping', 'session-new'], [$connection->status(), $connection->session_id]);
        $this->assertSame(['uid-1'], $connection->accounts->pluck('uid')->all());
    }

    public function testSavingTheMappingMakesTheConnectionImportable(): void
    {
        [$first, $second] = $this->consented();

        $response = $this->putJson('/api/fireflyx/v1/connections/nlb/accounts', [
            'accounts'     => [['id' => $first, 'firefly_account_id' => 7], ['id' => $second, 'firefly_account_id' => null]],
            'import_since' => Carbon::today()->subDays(30)->format('Y-m-d'),
        ], $this->auth());

        $response->assertOk()
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.accounts.0.firefly_account_id', 7)
            ->assertJsonPath('data.accounts.1.firefly_account_id', null);
        $this->artisan('fireflyx:render')->assertSuccessful();
        $rendered = json_decode((string) file_get_contents($this->importDir.'/nlb.json'), true);
        $this->assertSame(['uid-1' => 7], $rendered['accounts']);
        $this->assertSame(Carbon::today()->subDays(30)->format('Y-m-d'), $rendered['date_not_before'], 'first import reaches back to the chosen date');
    }

    public function testTheMappingNeedsAnAccountAndASaneDate(): void
    {
        [$first, $second] = $this->consented();
        $url              = '/api/fireflyx/v1/connections/nlb/accounts';

        $this->putJson($url, ['accounts' => [['id' => $first, 'firefly_account_id' => null]]], $this->auth())->assertStatus(422);
        $this->putJson($url, ['accounts' => [['id' => 99999, 'firefly_account_id' => 7]]], $this->auth())->assertStatus(422);
        $this->putJson($url, ['accounts' => [['id' => $first, 'firefly_account_id' => 0]]], $this->auth())->assertStatus(422);
        $this->putJson($url, ['accounts' => [['id' => $first, 'firefly_account_id' => 7]], 'import_since' => Carbon::today()->subDays(200)->format('Y-m-d')], $this->auth())->assertStatus(422);
        $this->putJson($url, ['accounts' => [['id' => $first, 'firefly_account_id' => 7]], 'import_since' => Carbon::tomorrow()->format('Y-m-d')], $this->auth())->assertStatus(422);
        $this->putJson($url, ['accounts' => [['id' => $first, 'firefly_account_id' => 7]], 'import_since' => 'last week'], $this->auth())->assertStatus(422);

        $this->assertSame('needs_mapping', Connection::where('name', 'nlb')->first()->status());
    }

    public function testAPendingConnectionCannotBeMapped(): void
    {
        $this->create();

        $this->putJson('/api/fireflyx/v1/connections/nlb/accounts', ['accounts' => []], $this->auth())->assertStatus(409);
    }

    /** @return array{0: int, 1: int} the two account row IDs */
    private function consented(): array
    {
        $this->create();
        $this->bank->nextSession = ['session_id' => 'session-new', 'accounts' => [
            ['uid' => 'uid-1', 'identification_hash' => 'hash-1', 'account_id' => ['iban' => 'SI56191000000123438'], 'currency' => 'EUR'],
            ['uid' => 'uid-2', 'identification_hash' => 'hash-2', 'account_id' => ['iban' => 'SI56191000000999999'], 'currency' => 'EUR'],
        ]];
        $this->get('/fireflyx/eb-callback?state='.$this->state().'&code=c')->assertRedirect();

        return Connection::with('accounts')->where('name', 'nlb')->first()->accounts->pluck('id')->all();
    }

    private function create(string $bank = 'NLB')
    {
        return $this->postJson('/api/fireflyx/v1/connections', ['country' => 'SI', 'bank' => $bank, 'psu_type' => 'personal', 'return_path' => '/bank-connections/list'], $this->auth());
    }

    private function state(): string
    {
        $calls = $this->bank->called('startAuthorisation');

        return $calls[count($calls) - 1][1]['state'];
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.str_repeat('t', 40)];
    }
}
