<?php

/*
 * ErrorsTest.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace Tests\Feature\FireflyX;

use App\Events\ImportedTransactions;
use App\FireflyX\Models\Connection;
use App\FireflyX\Models\ImportRun;
use App\FireflyX\Models\ManualImport;
use Carbon\Carbon;
use Tests\FireflyXTestCase;

/**
 * GET /errors: what Pico's errors page reads.
 */
final class ErrorsTest extends FireflyXTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-29 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function testNeedsTheToken(): void
    {
        $this->getJson('/api/fireflyx/v1/errors')->assertUnauthorized();
    }

    public function testRefusesABadDayOrConnection(): void
    {
        foreach (['x', '2026-13-01', '2026-02-30', '29.10.2026', '2026-10-29 03:00'] as $date) {
            $this->getJson('/api/fireflyx/v1/errors?date='.urlencode($date), $this->auth())->assertStatus(422);
        }
        $this->getJson('/api/fireflyx/v1/errors?connection=Bad%20Name', $this->auth())->assertStatus(422);
    }

    public function testTodayIsTheDefaultDay(): void
    {
        $this->getJson('/api/fireflyx/v1/errors', $this->auth())->assertOk()->assertJsonPath('data.date', '2026-10-29');
    }

    public function testShowsTheNightsAndTheManualRunsOfOneDay(): void
    {
        $nlb = $this->connection('nlb');
        $this->connection('abanka');
        Carbon::setTestNow('2026-10-29 03:05:00');
        event(new ImportedTransactions('nlb.json', [], [], [0 => ['Duplicate row', 'Duplicate row', 'Bad IBAN']], []));
        event(new ImportedTransactions('abanka.json', [0 => ['ok']], [], [], []));
        Carbon::setTestNow('2026-10-28 03:05:00');
        event(new ImportedTransactions('nlb.json', [], [], [0 => ['Yesterday']], []));
        Carbon::setTestNow('2026-10-29 12:00:00');
        $nlb->manualImports()->create(['date_from' => '2026-10-01', 'date_to' => '2026-10-28', 'status' => ManualImport::FAILED, 'error' => 'Enable Banking API error: 401', 'created_at' => '2026-10-29 10:00:00']);

        $data = $this->getJson('/api/fireflyx/v1/errors?date=2026-10-29', $this->auth())->assertOk()->json('data');

        $this->assertSame([
            'date'        => '2026-10-29',
            'count'       => 4,
            'connections' => [['name' => 'nlb', 'bank' => 'NLB']],
            'items'       => [
                ['at' => '2026-10-29T10:00:00+01:00', 'source' => 'manual', 'connection' => 'nlb', 'bank' => 'NLB', 'messages' => [['message' => 'Enable Banking API error: 401', 'count' => 1]], 'more' => 0],
                ['at' => '2026-10-29T03:05:00+01:00', 'source' => 'nightly', 'connection' => 'nlb', 'bank' => 'NLB', 'messages' => [['message' => 'Duplicate row', 'count' => 2], ['message' => 'Bad IBAN', 'count' => 1]], 'more' => 0],
            ],
        ], $data);
    }

    public function testFiltersByConnection(): void
    {
        $this->connection('nlb');
        $this->connection('abanka');
        ImportRun::create(['connection_id' => 1, 'connection_name' => 'nlb', 'ok' => false, 'errors' => 1, 'error_messages' => [['message' => 'a', 'count' => 1]], 'created_at' => '2026-10-29 03:05:00']);
        ImportRun::create(['connection_id' => 2, 'connection_name' => 'abanka', 'ok' => false, 'errors' => 1, 'error_messages' => [['message' => 'b', 'count' => 1]], 'created_at' => '2026-10-29 03:06:00']);

        $data = $this->getJson('/api/fireflyx/v1/errors?date=2026-10-29&connection=abanka', $this->auth())->assertOk()->json('data');

        $this->assertSame(['abanka'], array_column($data['items'], 'connection'));
        $this->assertSame(['abanka', 'nlb'], array_column($data['connections'], 'name'));
    }

    public function testARemovedBankKeepsItsNameAndAnOldRunKeepsItsFirstError(): void
    {
        $nlb = $this->connection('nlb');
        ImportRun::create(['connection_id' => $nlb->id, 'connection_name' => 'nlb', 'ok' => false, 'errors' => 3, 'first_error' => 'old', 'created_at' => '2026-10-29 03:05:00']);
        $nlb->manualImports()->create(['date_from' => '2026-10-01', 'date_to' => '2026-10-28', 'status' => ManualImport::FAILED, 'error' => 'manual', 'created_at' => '2026-10-29 04:00:00']);
        $nlb->delete();

        $data = $this->getJson('/api/fireflyx/v1/errors?date=2026-10-29', $this->auth())->assertOk()->json('data');

        $this->assertSame(['NLB', 'NLB'], array_column($data['items'], 'bank'));
        $this->assertSame(['nlb', 'nlb'], array_column($data['items'], 'connection'));
        $this->assertSame(2, $data['items'][1]['more']);
    }

    public function testTheDayIsTheLocalOne(): void
    {
        $nlb = $this->connection('nlb');
        ImportRun::create(['connection_id' => $nlb->id, 'connection_name' => 'nlb', 'ok' => false, 'errors' => 1, 'first_error' => 'late', 'created_at' => '2026-10-28 23:59:59']);
        ImportRun::create(['connection_id' => $nlb->id, 'connection_name' => 'nlb', 'ok' => false, 'errors' => 1, 'first_error' => 'early', 'created_at' => '2026-10-29 00:00:00']);

        $this->assertSame(['late'], $this->messagesOf('2026-10-28'));
        $this->assertSame(['early'], $this->messagesOf('2026-10-29'));
    }

    public function testHugeMultibyteMessagesStillAnswer(): void
    {
        $this->connection('nlb');
        event(new ImportedTransactions('nlb.json', [], [], [0 => array_map(static fn (int $i): string => $i.str_repeat('č', 1500), range(1, 60))], []));

        $data = $this->getJson('/api/fireflyx/v1/errors', $this->auth())->assertOk()->json('data');

        $this->assertCount(50, $data['items'][0]['messages']);
        $this->assertSame(10, $data['items'][0]['more']);
        $this->assertSame(60, $data['count']);
    }

    private function messagesOf(string $date): array
    {
        $data = $this->getJson('/api/fireflyx/v1/errors?date='.$date, $this->auth())->assertOk()->json('data');

        return array_map(static fn (array $item): string => $item['messages'][0]['message'], $data['items']);
    }

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.str_repeat('t', 40)];
    }

    private function connection(string $name): Connection
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
        ]);
        $connection->accounts()->create(['uid' => 'uid-'.$name, 'firefly_account_id' => 1]);

        return $connection;
    }
}
