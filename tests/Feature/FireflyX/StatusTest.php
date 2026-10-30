<?php

/*
 * StatusTest.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace Tests\Feature\FireflyX;

use App\Events\ImportedTransactions;
use App\FireflyX\Models\Connection;
use App\FireflyX\Models\ImportRun;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Tests\FireflyXTestCase;

/**
 * The nightly history written by RecordImportResult and the numbers
 * GET /status hands to Pico.
 */
final class StatusTest extends FireflyXTestCase
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

    public function testEveryImportedConnectionLeavesARunRow(): void
    {
        $this->connection('nlb');
        $this->connection('abanka');

        event(new ImportedTransactions('nlb.json', [0 => ['Created A', 'Created B']], [0 => ['w']], [], []));
        event(new ImportedTransactions('abanka.json', [], [], [3 => ['Enable Banking API error: 401']], []));

        $runs = ImportRun::orderBy('id')->get();
        $this->assertSame(['nlb', 'abanka'], $runs->pluck('connection_name')->all());
        $this->assertSame([true, false], $runs->pluck('ok')->all());
        $this->assertSame([2, 0], $runs->pluck('created')->all());
        $this->assertSame([1, 0], $runs->pluck('warnings')->all());
        $this->assertSame('Enable Banking API error: 401', $runs[1]->first_error);
        $this->assertSame('2026-10-29 12:00:00', $runs[0]->created_at->format('Y-m-d H:i:s'));
    }

    public function testAnImportOfAnUnknownConfigLeavesNoRow(): void
    {
        event(new ImportedTransactions('manual-3.json', [0 => ['Created A']], [], [], []));

        $this->assertSame(0, ImportRun::count());
    }

    public function testOldHistoryIsPruned(): void
    {
        $nlb = $this->connection('nlb');
        ImportRun::create(['connection_id' => $nlb->id, 'connection_name' => 'nlb', 'ok' => true, 'created_at' => Carbon::now()->subDays(181)]);
        ImportRun::create(['connection_id' => $nlb->id, 'connection_name' => 'nlb', 'ok' => true, 'created_at' => Carbon::now()->subDays(179)]);

        event(new ImportedTransactions('nlb.json', [], [], [], []));

        $this->assertSame(2, ImportRun::count());
    }

    public function testNeedsTheToken(): void
    {
        $this->getJson('/api/fireflyx/v1/status')->assertUnauthorized();
        $this->withToken('wrong')->getJson('/api/fireflyx/v1/status')->assertUnauthorized();
    }

    public function testRefusesABadWindow(): void
    {
        foreach (['0', '366', 'x', '-1'] as $days) {
            $this->withToken(str_repeat('t', 40))->getJson('/api/fireflyx/v1/status?days='.$days)->assertStatus(422);
        }
    }

    public function testTimelineShowsEveryDayAndMarksTheOnesNothingRan(): void
    {
        $nlb = $this->connection('nlb');
        $abanka = $this->connection('abanka');
        $this->makeRun($nlb, '2026-10-29 03:05:00', ok: true, created: 4);
        $this->makeRun($abanka, '2026-10-29 03:06:00', ok: false, errors: 1, firstError: 'Enable Banking API error: 401');
        $this->makeRun($nlb, '2026-10-27 03:05:00', ok: true, created: 2, warnings: 1);

        $data = $this->withToken(str_repeat('t', 40))->getJson('/api/fireflyx/v1/status?days=4')->assertOk()->json('data');

        $this->assertSame(['2026-10-29', '2026-10-28', '2026-10-27', '2026-10-26'], array_column($data['imports']['days'], 'date'));
        $this->assertSame([true, false, true, false], array_column($data['imports']['days'], 'ran'));
        $this->assertEquals(['connections' => 2, 'ok' => 1, 'failed' => 1, 'created' => 4, 'errors' => 1], array_intersect_key($data['imports']['days'][0], array_flip(['connections', 'ok', 'failed', 'created', 'errors'])));
        $this->assertSame(['runs' => 3, 'ok' => 2, 'failed' => 1, 'created' => 6, 'warnings' => 1, 'errors' => 1], $data['imports']['totals']);
        $this->assertSame([['at' => '2026-10-29T03:06:00+01:00', 'connection' => 'abanka', 'error' => 'Enable Banking API error: 401']], $data['imports']['recent_failures']);
        $this->assertSame('2026-10-27T03:05:00+01:00', $data['imports']['tracking_since']);
    }

    public function testRunsBeforeTheWindowAreLeftOut(): void
    {
        $nlb = $this->connection('nlb');
        $this->makeRun($nlb, '2026-09-01 03:05:00', ok: true, created: 9);

        $data = $this->withToken(str_repeat('t', 40))->getJson('/api/fireflyx/v1/status?days=7')->assertOk()->json('data');

        $this->assertSame(0, $data['imports']['totals']['runs']);
        $this->assertSame('2026-09-01T03:05:00+02:00', $data['imports']['tracking_since'], 'tracking_since is about the whole history, not the window');
    }

    public function testListsConnectionsWithoutCallingTheBank(): void
    {
        Http::fake();
        $this->connection('nlb', ['valid_until' => Carbon::now()->addDays(10), 'last_import_at' => Carbon::now()->subHours(9), 'last_import_ok' => true]);
        $this->connection('half', ['lifecycle' => Connection::LIFECYCLE_PENDING]);

        $data = $this->withToken(str_repeat('t', 40))->getJson('/api/fireflyx/v1/status')->assertOk()->json('data');

        $this->assertCount(1, $data['connections']);
        $this->assertSame(['nlb', 'NLB', 'expiring', 10, true], [$data['connections'][0]['name'], $data['connections'][0]['bank'], $data['connections'][0]['status'], $data['connections'][0]['days_left'], $data['connections'][0]['last_import_ok']]);
        Http::assertNothingSent();
    }

    public function testPassesOnTheSuggestersStats(): void
    {
        config(['fireflyx.suggester_url' => 'http://suggester.test', 'fireflyx.suggester_token' => str_repeat('s', 40)]);
        Http::fake(['suggester.test/stats*' => Http::response(['totals' => ['calls' => 7, 'low_confidence' => 2], 'suggestions' => ['pending' => 3]])]);

        $data = $this->withToken(str_repeat('t', 40))->getJson('/api/fireflyx/v1/status?days=14')->assertOk()->json('data');

        $this->assertTrue($data['suggester']['available']);
        $this->assertSame(7, $data['suggester']['totals']['calls']);
        Http::assertSent(static fn ($request): bool => str_contains($request->url(), 'days=14') && $request->hasHeader('Authorization', 'Bearer '.str_repeat('s', 40)));
    }

    public function testASuggesterThatIsDownDoesNotBreakTheStatus(): void
    {
        config(['fireflyx.suggester_url' => 'http://suggester.test', 'fireflyx.suggester_token' => str_repeat('s', 40)]);
        Http::fake(['suggester.test/stats*' => Http::response(['error' => 'cannot read the run history: disk I/O error'], 503)]);

        $data = $this->withToken(str_repeat('t', 40))->getJson('/api/fireflyx/v1/status')->assertOk()->json('data');

        $this->assertSame(['available' => false, 'error' => 'cannot read the run history: disk I/O error'], $data['suggester']);
        $this->assertArrayHasKey('imports', $data);
    }

    public function testSuggesterNotConfigured(): void
    {
        $data = $this->withToken(str_repeat('t', 40))->getJson('/api/fireflyx/v1/status')->assertOk()->json('data');

        $this->assertFalse($data['suggester']['available']);
    }

    private function makeRun(Connection $connection, string $at, bool $ok, int $created = 0, int $warnings = 0, int $errors = 0, ?string $firstError = null): void
    {
        ImportRun::create([
            'connection_id'   => $connection->id,
            'connection_name' => $connection->name,
            'ok'              => $ok,
            'created'         => $created,
            'warnings'        => $warnings,
            'errors'          => $errors,
            'first_error'     => $firstError,
            'created_at'      => Carbon::parse($at),
        ]);
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
