<?php

/*
 * ApiAndAdoptTest.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace Tests\Feature\FireflyX;

use App\FireflyX\Models\Connection;
use Carbon\Carbon;
use Tests\FireflyXTestCase;

final class ApiAndAdoptTest extends FireflyXTestCase
{
    public function testApiRequiresTheToken(): void
    {
        $this->getJson('/api/fireflyx/v1/connections')->assertUnauthorized();
        $this->getJson('/api/fireflyx/v1/connections', ['Authorization' => 'Bearer wrong'])->assertUnauthorized();
    }

    public function testApiIsOffWithAShortToken(): void
    {
        config(['fireflyx.api_token' => 'short']);

        $this->getJson('/api/fireflyx/v1/connections', ['Authorization' => 'Bearer short'])->assertUnauthorized();
    }

    public function testListsConnectionsWithoutLeakingTheSession(): void
    {
        $this->adoptFixture();
        Connection::where('name', 'nlb')->update(['eb_status' => 'AUTHORIZED', 'valid_until' => Carbon::now()->addDays(10), 'checked_at' => Carbon::now()]);

        $response = $this->getJson('/api/fireflyx/v1/connections', ['Authorization' => 'Bearer '.str_repeat('t', 40)]);

        $response->assertOk()
            ->assertJsonPath('data.0.name', 'nlb')
            ->assertJsonPath('data.0.status', 'expiring')
            ->assertJsonPath('data.0.bank.name', 'NLB')
            ->assertJsonPath('data.0.accounts.0.firefly_account_id', 1);
        $this->assertStringNotContainsString('00000000-0000-4000-8000-00000000aaaa', $response->getContent());
        $this->assertStringNotContainsString('00000000-0000-4000-8000-00000000bbbb', $response->getContent());
    }

    public function testAdoptKeepsOnlyTheConnectionAndRendersTheSameConfig(): void
    {
        $this->adoptFixture();
        Connection::where('name', 'nlb')->update(['eb_status' => 'AUTHORIZED', 'valid_until' => Carbon::now()->addDays(80)]);

        $this->artisan('fireflyx:render')->assertSuccessful();

        $rendered = json_decode((string) file_get_contents($this->importDir.'/nlb.json'), true);
        $exported = $this->fixture('exported-nlb.json');
        foreach (['date_not_before', 'date_range_number'] as $windowKey) {
            unset($rendered[$windowKey], $exported[$windowKey]);
        }
        ksort($rendered);
        ksort($exported);
        $this->assertSame($exported, $rendered);
    }

    public function testAdoptRefusesSettingsThatDifferFromTheBase(): void
    {
        $config          = $this->fixture('exported-nlb.json');
        $config['rules'] = false;
        $file            = $this->importDir.'/different.json';
        file_put_contents($file, json_encode($config));

        $this->artisan('fireflyx:connections:adopt', ['file' => $file, '--offline' => true])
            ->expectsOutput('These settings differ from the base config: rules')
            ->assertFailed();
        $this->assertSame(0, Connection::count());
    }

    public function testAnUnreachableBankIsRecordedNotThrown(): void
    {
        $this->adoptFixture();
        config(['eb.url' => 'http://127.0.0.1:9']);

        $this->artisan('fireflyx:connections:refresh')->assertSuccessful();

        $connection = Connection::where('name', 'nlb')->first();
        $this->assertNotNull($connection->last_error);
        $this->assertSame('error', $connection->status());
    }

    public function testReplaceSwapsSessionAndAccountsTogether(): void
    {
        $this->adoptFixture();
        $relinked                            = $this->fixture('exported-nlb.json');
        $relinked['enable_banking_sessions'] = ['new-session'];
        $relinked['accounts']                = ['new-uid' => 1];
        $file                                = $this->importDir.'/relinked.json';
        file_put_contents($file, json_encode($relinked));

        $this->artisan('fireflyx:connections:adopt', ['file' => $file, '--name' => 'nlb', '--offline' => true])
            ->expectsOutputToContain('already exists (use --replace')
            ->assertFailed();
        $this->artisan('fireflyx:connections:adopt', ['file' => $file, '--name' => 'nlb', '--offline' => true, '--replace' => true])
            ->expectsOutputToContain('Replaced nlb')
            ->assertSuccessful();

        $connection = Connection::with('accounts')->where('name', 'nlb')->first();
        $this->assertSame('new-session', $connection->session_id);
        $this->assertSame(['new-uid'], $connection->accounts->pluck('uid')->all());
        $this->assertNotNull($connection->relinked_at);
        $this->assertSame('2026-08-31', $connection->created_at->format('Y-m-d'), 'created_at is kept');
    }

    private function adoptFixture(): void
    {
        $this->artisan('fireflyx:connections:adopt', ['file' => __DIR__.'/../../fixtures/fireflyx/exported-nlb.json', '--name' => 'nlb', '--offline' => true])
            ->expectsOutputToContain('Adopted nlb: NLB SI, 1 account(s)')
            ->assertSuccessful();
    }
}
