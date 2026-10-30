<?php

/*
 * ConnectionEventTest.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace Tests\Feature\FireflyX;

use App\FireflyX\EnableBanking\EnableBankingGateway;
use App\FireflyX\Models\Connection;
use App\FireflyX\Models\ConnectionEvent;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\FireflyXTestCase;

/**
 * What the status page can say about a bank connection afterwards: each step
 * of the consent flow, and refreshes that failed or found the consent ended.
 */
final class ConnectionEventTest extends FireflyXTestCase
{
    private FakeGateway $bank;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bank        = new FakeGateway();
        $this->bank->banks = ['SI' => [['name' => 'NLB', 'country' => 'SI', 'logo' => null, 'max_consent_days' => 180, 'psu_types' => ['personal']]]];
        $this->app->instance(EnableBankingGateway::class, $this->bank);
        config(['fireflyx.callback_url' => 'https://import.example.test/eb-callback', 'fireflyx.pico_url' => 'https://pico.example.test']);
        Carbon::setTestNow('2026-10-29 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testADeclinedNewConsentIsRememberedAfterItsConnectionIsGone(): void
    {
        $this->create();

        $this->get('/fireflyx/eb-callback?state='.$this->state().'&error=access_denied&error_description=User+cancelled')->assertRedirect();

        $this->assertSame(0, Connection::withTrashed()->count(), 'the pending connection is deleted');
        $events = ConnectionEvent::orderBy('id')->get();
        $this->assertSame(['consent_started', 'consent_declined'], $events->pluck('step')->all());
        $this->assertSame(['nlb', 'nlb'], $events->pluck('connection_name')->all());
        $this->assertSame(['NLB', 'NLB'], $events->pluck('bank')->all());
        $this->assertSame(['create', 'create'], $events->pluck('flow')->all());
        $this->assertSame([ConnectionEvent::INFO, ConnectionEvent::WARNING], $events->pluck('level')->all());
        $this->assertSame('bank_declined', $events[1]->reason);
        $this->assertSame('error=access_denied, description=User cancelled', $events[1]->message);
    }

    public function testASuccessfulLinkTellsTheStoryToTheMapping(): void
    {
        $this->create();
        $this->bank->nextSession = ['session_id' => 'session-new', 'accounts' => [
            ['uid' => 'uid-1', 'identification_hash' => 'hash-1', 'account_id' => ['iban' => 'SI56191000000123438'], 'currency' => 'EUR'],
            ['uid' => 'uid-2', 'identification_hash' => 'hash-2', 'account_id' => ['iban' => 'SI56191000000999999'], 'currency' => 'EUR'],
        ]];
        $this->get('/fireflyx/eb-callback?state='.$this->state().'&code=c')->assertRedirect();
        $accountId = Connection::with('accounts')->where('name', 'nlb')->first()->accounts->first()->id;

        $this->putJson('/api/fireflyx/v1/connections/nlb/accounts', ['accounts' => [['id' => $accountId, 'firefly_account_id' => 3]]], $this->auth())->assertOk();

        $events = ConnectionEvent::orderBy('id')->get();
        $this->assertSame(['consent_started', 'consent_completed', 'accounts_saved'], $events->pluck('step')->all());
        $this->assertSame('2 bank account(s) shared.', $events[1]->message);
        $this->assertSame('1 of 2 bank account(s) will be imported.', $events[2]->message);
        $this->assertSame([null, null, null], [$events[0]->reason, $events[1]->reason, $events[2]->reason]);
    }

    public function testNoEventHoldsAnIbanOrASessionId(): void
    {
        $this->create();
        $this->bank->nextSession = ['session_id' => 'session-secret', 'accounts' => [['uid' => 'uid-1', 'account_id' => ['iban' => 'SI56191000000123438'], 'name' => 'Janez Novak', 'currency' => 'EUR']]];
        $this->get('/fireflyx/eb-callback?state='.$this->state().'&code=the-code')->assertRedirect();

        $everything = ConnectionEvent::all()->map(static fn (ConnectionEvent $event): string => json_encode($event->getAttributes()))->implode(' ');

        foreach (['SI56191000000123438', 'Janez', 'session-secret', 'the-code', 'new-auth-id'] as $secret) {
            $this->assertStringNotContainsString($secret, $everything);
        }
    }

    public function testASessionThatCouldNotBeCreatedAndAnEmptyConsentAreRecorded(): void
    {
        $this->create();
        $this->bank->failCreateSession = true;
        $this->get('/fireflyx/eb-callback?state='.$this->state().'&code=c')->assertRedirect();

        $this->create();
        $this->bank->failCreateSession = false;
        $this->bank->nextSession       = ['session_id' => 'session-empty', 'accounts' => []];
        $this->get('/fireflyx/eb-callback?state='.$this->state().'&code=c')->assertRedirect();

        $steps = ConnectionEvent::orderBy('id')->get(['step', 'level', 'reason'])->map(static fn (ConnectionEvent $event): string => implode('/', [$event->step, $event->level, $event->reason]))->all();
        $this->assertSame([
            'consent_started/info/',
            'session_failed/error/session_failed',
            'consent_started/info/',
            'no_accounts/warning/no_accounts',
        ], $steps);
    }

    public function testAConsentTheBankRefusedToStartIsRecorded(): void
    {
        $this->bank->failStartAuth = true;

        $this->create()->assertStatus(502);

        $this->assertSame(0, Connection::withTrashed()->count());
        $event = ConnectionEvent::first();
        $this->assertSame(['consent_start_failed', 'error', 'eb_refused', 'create', 'nlb'], [$event->step, $event->level, $event->reason, $event->flow, $event->connection_name]);
    }

    public function testACallbackWithAnUnknownStateHasNoConnectionToBlame(): void
    {
        $this->get('/fireflyx/eb-callback?state=fx-doesnotexist&code=c')->assertRedirect();

        $event = ConnectionEvent::first();
        $this->assertSame(['callback_rejected', 'error', 'invalid_state'], [$event->step, $event->level, $event->reason]);
        $this->assertNull($event->connection_name);
    }

    public function testARefreshThatFailsIsRecordedOnceAndItsRecoveryOnce(): void
    {
        $this->nlb();

        // Three page loads while the bank is unreachable: one event, not three.
        $this->artisan('fireflyx:connections:refresh');
        $this->artisan('fireflyx:connections:refresh');
        $this->artisan('fireflyx:connections:refresh');
        $this->assertSame(['refresh_failed'], ConnectionEvent::pluck('step')->all());
        $this->assertSame('SESSION_DOES_NOT_EXIST', ConnectionEvent::first()->message);

        $this->bank->sessions['session-old'] = ['status' => 'AUTHORIZED', 'access' => ['valid_until' => Carbon::now()->addDays(60)->toW3cString()], 'accounts_data' => []];
        $this->artisan('fireflyx:connections:refresh');
        $this->artisan('fireflyx:connections:refresh');

        $this->assertSame(['refresh_failed', 'refresh_recovered'], ConnectionEvent::orderBy('id')->pluck('step')->all());
    }

    public function testAnEndedConsentIsRecordedOnTheNightItIsFound(): void
    {
        $this->nlb();
        $this->bank->sessions['session-old'] = ['status' => 'AUTHORIZED', 'access' => ['valid_until' => Carbon::now()->addDays(60)->toW3cString()], 'accounts_data' => []];
        $this->artisan('fireflyx:connections:refresh');
        $this->assertSame(0, ConnectionEvent::count());

        $this->bank->sessions['session-old'] = ['status' => 'EXPIRED', 'access' => ['valid_until' => Carbon::now()->subDay()->toW3cString()], 'accounts_data' => []];
        $this->artisan('fireflyx:connections:refresh');
        $this->artisan('fireflyx:connections:refresh');

        $this->assertSame(['consent_ended'], ConnectionEvent::pluck('step')->all());
        $this->assertSame([ConnectionEvent::WARNING, 'expired', 'Enable Banking reports the consent as EXPIRED.'], [ConnectionEvent::first()->level, ConnectionEvent::first()->reason, ConnectionEvent::first()->message]);
    }

    public function testRemovingAConnectionIsRecorded(): void
    {
        $this->nlb();

        $this->deleteJson('/api/fireflyx/v1/connections/nlb', [], $this->auth())->assertNoContent();

        $this->assertSame(['removed'], ConnectionEvent::pluck('step')->all());
    }

    public function testMessagesAreOneBoundedLine(): void
    {
        ConnectionEvent::record('nlb', 'refresh_failed', ConnectionEvent::ERROR, 'refresh_failed', "first line\n\n  second   line ".str_repeat('x', 500));

        $message = ConnectionEvent::first()->message;
        $this->assertStringStartsWith('first line second line xxx', $message);
        $this->assertStringNotContainsString("\n", $message);
        $this->assertLessThanOrEqual(303, mb_strlen($message));
    }

    public function testOldEventsArePruned(): void
    {
        ConnectionEvent::create(['connection_name' => 'old', 'step' => 'removed', 'level' => 'info', 'created_at' => Carbon::now()->subDays(91)]);
        ConnectionEvent::create(['connection_name' => 'recent', 'step' => 'removed', 'level' => 'info', 'created_at' => Carbon::now()->subDays(89)]);

        ConnectionEvent::record('nlb', 'removed');

        $this->assertSame(['recent', 'nlb'], ConnectionEvent::orderBy('id')->pluck('connection_name')->all());
    }

    public function testAFlowStillWorksWhenTheEventCannotBeWritten(): void
    {
        Schema::drop('fireflyx_connection_events');

        $this->create()->assertOk()->assertJsonPath('data.name', 'nlb');
    }

    public function testTheStatusListsTheLatestEventsAndCountsTheBadOnes(): void
    {
        $this->create();
        $this->get('/fireflyx/eb-callback?state='.$this->state().'&error=access_denied')->assertRedirect();
        $this->get('/fireflyx/eb-callback?state=fx-nope&code=c')->assertRedirect();
        ConnectionEvent::create(['connection_name' => 'old', 'step' => 'refresh_failed', 'level' => 'error', 'created_at' => Carbon::now()->subDays(40)]);

        $data = $this->getJson('/api/fireflyx/v1/status?days=30', $this->auth())->assertOk()->json('data.events');

        $this->assertSame(['error' => 1, 'warning' => 1], $data['totals']);
        $this->assertSame(['callback_rejected', 'consent_declined', 'consent_started'], array_column($data['recent'], 'step'), 'newest first, the old one is outside the window');
        $this->assertSame(['at' => '2026-10-29T12:00:00+01:00', 'connection' => 'nlb', 'bank' => 'NLB', 'flow' => 'create', 'step' => 'consent_declined', 'level' => 'warning', 'reason' => 'bank_declined', 'message' => 'error=access_denied, description='], $data['recent'][1]);
    }

    private function create()
    {
        return $this->postJson('/api/fireflyx/v1/connections', ['country' => 'SI', 'bank' => 'NLB', 'psu_type' => 'personal', 'return_path' => '/bank-connections/list'], $this->auth());
    }

    private function state(): string
    {
        $calls = $this->bank->called('startAuthorisation');

        return $calls[count($calls) - 1][1]['state'];
    }

    private function nlb(): Connection
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
            'checked_at'   => Carbon::now()->subDays(2),
        ]);
        $connection->accounts()->create(['uid' => 'uid-old', 'identification_hash' => 'hash-1', 'iban' => 'SI56191000000123438', 'firefly_account_id' => 1]);

        return $connection;
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.str_repeat('t', 40)];
    }
}
