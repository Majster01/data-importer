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

    public function testRefusesWhatItCannotRead(): void
    {
        $bad = ['from=x', 'to=2026-13-01', 'to=2026-02-30', 'from=29.10.2026', 'from=2026-10-30&to=2026-10-29', 'from=2025-01-01&to=2026-10-29', 'connection=Bad%20Name', 'source=other', 'page=0', 'page=x', 'limit=0', 'limit=101', 'q='.str_repeat('x', 101)];
        foreach ($bad as $query) {
            $this->getJson('/api/fireflyx/v1/errors?'.$query, $this->auth())->assertStatus(422);
        }
    }

    public function testARangeOfAYearIsTheMostItTakes(): void
    {
        // The last two pairs cross changes of daylight saving time (2025-03-30, 2025-10-26, 2026-03-29): a year is still 365 calendar days
        $this->getJson('/api/fireflyx/v1/errors?from=2025-10-29&to=2026-10-29', $this->auth())->assertOk();
        $this->getJson('/api/fireflyx/v1/errors?from=2025-10-28&to=2026-10-29', $this->auth())->assertStatus(422);
        $this->getJson('/api/fireflyx/v1/errors?from=2025-03-30&to=2026-03-30', $this->auth())->assertOk();
        $this->getJson('/api/fireflyx/v1/errors?from=2025-03-29&to=2026-03-30', $this->auth())->assertStatus(422);
    }

    public function testTheLastThirtyDaysUnlessAskedOtherwise(): void
    {
        $data = $this->getJson('/api/fireflyx/v1/errors', $this->auth())->assertOk()->json('data');

        $this->assertSame(['2026-09-30', '2026-10-29'], [$data['from'], $data['to']]);
    }

    public function testListsEveryErrorOfTheDaysAsOneEntryEach(): void
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

        $data = $this->getJson('/api/fireflyx/v1/errors?from=2026-10-29&to=2026-10-29', $this->auth())->assertOk()->json('data');

        $this->assertSame(['2026-10-29', '2026-10-29', 3, 1, false], [$data['from'], $data['to'], $data['total'], $data['page'], $data['has_more']]);
        $this->assertSame([['name' => 'nlb', 'bank' => 'NLB']], $data['connections']);
        $this->assertSame(
            [['manual', 'Enable Banking API error: 401', 1], ['nightly', 'Duplicate row', 2], ['nightly', 'Bad IBAN', 1]],
            array_map(static fn (array $item): array => [$item['source'], $item['message'], $item['count']], $data['items'])
        );
        $this->assertSame(['at' => '2026-10-29T10:00:00+01:00', 'connection' => 'nlb', 'bank' => 'NLB'], array_intersect_key($data['items'][0], array_flip(['at', 'connection', 'bank'])));

        $both = $this->getJson('/api/fireflyx/v1/errors?from=2026-10-28&to=2026-10-29', $this->auth())->json('data');
        $this->assertSame(4, $both['total'], 'two days, newest first');
        $this->assertSame('Yesterday', $both['items'][3]['message']);
    }

    public function testFiltersBySourceConnectionAndText(): void
    {
        $this->connection('nlb');
        $this->connection('abanka');
        ImportRun::create(['connection_id' => 1, 'connection_name' => 'nlb', 'ok' => false, 'errors' => 1, 'error_messages' => [['message' => 'Bad IBAN', 'count' => 1]], 'created_at' => '2026-10-29 03:05:00']);
        ImportRun::create(['connection_id' => 2, 'connection_name' => 'abanka', 'ok' => false, 'errors' => 1, 'error_messages' => [['message' => 'Zero amount', 'count' => 1]], 'created_at' => '2026-10-29 03:06:00']);

        $byBank = $this->getJson('/api/fireflyx/v1/errors?connection=abanka', $this->auth())->json('data');
        $byText = $this->getJson('/api/fireflyx/v1/errors?q=iban', $this->auth())->json('data');
        $manual = $this->getJson('/api/fireflyx/v1/errors?source=manual', $this->auth())->json('data');

        $this->assertSame(['Zero amount'], array_column($byBank['items'], 'message'));
        $this->assertSame(['abanka', 'nlb'], array_column($byBank['connections'], 'name'));
        $this->assertSame(['Bad IBAN'], array_column($byText['items'], 'message'));
        $this->assertSame([], $manual['items']);
    }

    public function testPagesTheList(): void
    {
        $this->connection('nlb');
        ImportRun::create(['connection_id' => 1, 'connection_name' => 'nlb', 'ok' => false, 'errors' => 3, 'error_messages' => [['message' => 'a', 'count' => 1], ['message' => 'b', 'count' => 1], ['message' => 'c', 'count' => 1]], 'created_at' => '2026-10-29 03:05:00']);

        $one = $this->getJson('/api/fireflyx/v1/errors?limit=2', $this->auth())->json('data');
        $two = $this->getJson('/api/fireflyx/v1/errors?limit=2&page=2', $this->auth())->json('data');

        $this->assertSame([['a', 'b'], true, 3], [array_column($one['items'], 'message'), $one['has_more'], $one['total']]);
        $this->assertSame([['c'], false], [array_column($two['items'], 'message'), $two['has_more']]);
    }

    public function testARemovedBankKeepsItsNameAndAnOldRunKeepsItsFirstError(): void
    {
        $nlb = $this->connection('nlb');
        ImportRun::create(['connection_id' => $nlb->id, 'connection_name' => 'nlb', 'ok' => false, 'errors' => 3, 'first_error' => 'old', 'created_at' => '2026-10-29 03:05:00']);
        $nlb->manualImports()->create(['date_from' => '2026-10-01', 'date_to' => '2026-10-28', 'status' => ManualImport::FAILED, 'error' => 'manual', 'created_at' => '2026-10-29 04:00:00']);
        $nlb->delete();

        $items = $this->getJson('/api/fireflyx/v1/errors', $this->auth())->assertOk()->json('data.items');

        $this->assertSame(['manual', 'old', null], array_column($items, 'message'));
        $this->assertSame(['NLB', 'NLB', 'NLB'], array_column($items, 'bank'));
        $this->assertSame(2, $items[2]['count']);
    }

    public function testTheDayIsTheLocalOne(): void
    {
        $nlb = $this->connection('nlb');
        ImportRun::create(['connection_id' => $nlb->id, 'connection_name' => 'nlb', 'ok' => false, 'errors' => 1, 'first_error' => 'late', 'created_at' => '2026-10-28 23:59:59']);
        ImportRun::create(['connection_id' => $nlb->id, 'connection_name' => 'nlb', 'ok' => false, 'errors' => 1, 'first_error' => 'early', 'created_at' => '2026-10-29 00:00:00']);

        $this->assertSame(['late'], $this->messagesOf('from=2026-10-28&to=2026-10-28'));
        $this->assertSame(['early'], $this->messagesOf('from=2026-10-29&to=2026-10-29'));
        $this->assertSame(['early', 'late'], $this->messagesOf('from=2026-10-28&to=2026-10-29'));
    }

    public function testHugeMultibyteMessagesStillAnswer(): void
    {
        $this->connection('nlb');
        event(new ImportedTransactions('nlb.json', [], [], [0 => array_map(static fn (int $i): string => $i.str_repeat('č', 1500), range(1, 60))], []));

        $data = $this->getJson('/api/fireflyx/v1/errors?limit=100', $this->auth())->assertOk()->json('data');

        $this->assertSame(51, $data['total']);
        $this->assertSame([null, 10], [$data['items'][50]['message'], $data['items'][50]['count']]);
    }

    private function messagesOf(string $query): array
    {
        $data = $this->getJson('/api/fireflyx/v1/errors?'.$query, $this->auth())->assertOk()->json('data');

        return array_column($data['items'], 'message');
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
