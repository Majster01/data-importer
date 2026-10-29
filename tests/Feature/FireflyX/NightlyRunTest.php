<?php

/*
 * NightlyRunTest.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace Tests\Feature\FireflyX;

use App\Events\ImportedTransactions;
use App\FireflyX\Models\Connection;
use Carbon\Carbon;
use Tests\FireflyXTestCase;

/**
 * render → (upstream autoimport fires ImportedTransactions) → report: the path
 * that makes a connection saved during the day import at the next nightly run.
 */
final class NightlyRunTest extends FireflyXTestCase
{
    public function testRendersReadyConnectionsAndPrunesTheRest(): void
    {
        $this->connection('nlb');
        $this->connection('gone', ['eb_status' => 'EXPIRED']);
        $this->connection('half', ['lifecycle' => Connection::LIFECYCLE_NEEDS_MAPPING]);
        file_put_contents($this->importDir.'/removed-bank.json', '{}');

        $this->artisan('fireflyx:render')
            ->expectsOutputToContain('nlb → 1 account(s)')
            ->expectsOutput('skip gone: expired')
            ->expectsOutput('skip half: needs_mapping')
            ->expectsOutput('pruned removed-bank.json')
            ->assertSuccessful();

        $this->assertSame(['nlb.json'], array_map('basename', glob($this->importDir.'/*.json')));
        $rendered = json_decode((string) file_get_contents($this->importDir.'/nlb.json'), true);
        $this->assertSame(['session-nlb'], $rendered['enable_banking_sessions']);
        $this->assertSame('0600', substr(sprintf('%o', fileperms($this->importDir.'/nlb.json')), -4));
        $this->assertSame([], glob($this->importDir.'/.*.json.*'), 'no temp files left behind');
    }

    public function testInvalidBaseWritesAndPrunesNothing(): void
    {
        $this->connection('nlb');
        file_put_contents($this->importDir.'/nlb.json', '{"yesterday":true}');
        config(['fireflyx.base_config' => $this->importDir.'/missing-base.json']);

        $this->artisan('fireflyx:render')->assertFailed();

        $this->assertSame('{"yesterday":true}', file_get_contents($this->importDir.'/nlb.json'));
    }

    public function testDryRunMasksTheSession(): void
    {
        $this->connection('nlb');

        $this->artisan('fireflyx:render --dry-run')
            ->expectsOutputToContain('<masked>')
            ->doesntExpectOutputToContain('session-nlb')
            ->assertSuccessful();
        $this->assertSame([], glob($this->importDir.'/*.json'));
    }

    public function testReportPassesOnlyWhenEveryConnectionImportedCleanly(): void
    {
        $this->connection('nlb', ['import_since' => '2026-09-01']);
        $this->connection('abanka');
        $started = Carbon::now()->subMinute()->toIso8601String();

        event(new ImportedTransactions('nlb.json', [0 => ['Created 3 transactions']], [], [], []));

        $this->artisan('fireflyx:connections:report', ['--since' => $started])
            ->expectsOutput('! abanka: NOT imported in this run')
            ->expectsOutput('nlb: ok, 1 message(s), 0 warning(s)')
            ->assertFailed();
        $this->assertNull(Connection::where('name', 'nlb')->first()->import_since, 'history is only fetched until the first clean import');

        event(new ImportedTransactions('abanka.json', [], [], [3 => ['Enable Banking API error: 401']], []));

        $this->artisan('fireflyx:connections:report', ['--since' => $started])
            ->expectsOutput('! abanka: imported with 1 error(s): Enable Banking API error: 401')
            ->assertFailed();

        event(new ImportedTransactions('abanka.json', [], [], [], []));

        $this->artisan('fireflyx:connections:report', ['--since' => $started])->assertSuccessful();
    }

    private function connection(string $name, array $attributes = []): Connection
    {
        $connection = Connection::create([
            'name'         => $name,
            'lifecycle'    => Connection::LIFECYCLE_READY,
            'bank_name'    => strtoupper($name),
            'bank_country' => 'SI',
            'session_id'   => 'session-'.$name,
            'auth_id'      => 'auth-'.$name,
            'eb_status'    => 'AUTHORIZED',
            'valid_until'  => Carbon::now()->addDays(80),
            'checked_at'   => Carbon::now(),
            ...$attributes,
        ]);
        $connection->accounts()->create(['uid' => 'uid-'.$name, 'firefly_account_id' => 1]);

        return $connection;
    }
}
