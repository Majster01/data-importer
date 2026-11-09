<?php

/*
 * ErrorFeedTest.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace Tests\Feature\FireflyX;

use App\Events\ImportedTransactions;
use App\FireflyX\Http\ErrorFeed;
use App\FireflyX\Listeners\RecordImportResult;
use App\FireflyX\Models\Connection;
use App\FireflyX\Models\ImportRun;
use App\FireflyX\Models\ManualImport;
use Carbon\Carbon;
use Tests\FireflyXTestCase;

/**
 * Built from unsaved rows: ErrorFeed does no queries (the models only need the schema).
 */
final class ErrorFeedTest extends FireflyXTestCase
{
    private const BANKS = ['nlb' => 'NLB', 'abanka' => 'Abanka'];

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

    public function testNothingToListIsAnEmptyFeed(): void
    {
        $feed = $this->feed([], []);

        $this->assertSame(['total' => 0, 'page' => 1, 'has_more' => false, 'connections' => [], 'items' => []], $feed);
    }

    public function testEveryDistinctMessageIsOneEntryNewestImportFirst(): void
    {
        $runs    = [$this->nightly(1, 'nlb', '2026-10-29 03:05:00', 3, [['message' => 'a', 'count' => 2], ['message' => 'b', 'count' => 1]])];
        $manuals = [$this->manual(1, 'abanka', '2026-10-29 10:00:00', 'failed', 1, [['message' => 'c', 'count' => 1]], 'c')];

        $feed = $this->feed($runs, $manuals);

        $this->assertSame(3, $feed['total']);
        $this->assertSame([
            ['id' => 'manual-1-0', 'at' => '2026-10-29T10:00:00+01:00', 'source' => 'manual', 'connection' => 'abanka', 'bank' => 'Abanka', 'message' => 'c', 'count' => 1],
            ['id' => 'nightly-1-0', 'at' => '2026-10-29T03:05:00+01:00', 'source' => 'nightly', 'connection' => 'nlb', 'bank' => 'NLB', 'message' => 'a', 'count' => 2],
            ['id' => 'nightly-1-1', 'at' => '2026-10-29T03:05:00+01:00', 'source' => 'nightly', 'connection' => 'nlb', 'bank' => 'NLB', 'message' => 'b', 'count' => 1],
        ], $feed['items']);
        $this->assertSame([['name' => 'abanka', 'bank' => 'Abanka'], ['name' => 'nlb', 'bank' => 'NLB']], $feed['connections']);
    }

    public function testRunsThatHadNoErrorsAreLeftOut(): void
    {
        $this->assertSame([], $this->feed([$this->nightly(1, 'nlb', '2026-10-29 03:05:00', 0, null)], [])['items']);
    }

    public function testWhatTheListDoesNotShowIsOneEntryWithoutAMessage(): void
    {
        $run = $this->nightly(7, 'nlb', '2026-10-29 03:05:00', 120, [['message' => 'a', 'count' => 30], ['message' => 'b', 'count' => 20]]);

        $items = $this->feed([$run], [])['items'];

        $this->assertSame(['a', 'b', null], array_column($items, 'message'));
        $this->assertSame(['nightly-7-more', 70], [$items[2]['id'], $items[2]['count']]);
    }

    public function testRowsFromBeforeTheListFallBackToTheFirstError(): void
    {
        $runs    = [$this->nightly(1, 'nlb', '2026-10-29 03:05:00', 3, null, 'old first error')];
        $manuals = [$this->manual(1, 'abanka', '2026-10-29 10:00:00', 'failed', null, null, 'old manual error')];

        $items = $this->feed($runs, $manuals)['items'];

        $this->assertSame(['old manual error', 'old first error', null], array_column($items, 'message'));
        $this->assertSame([1, 1, 2], array_column($items, 'count'), 'a manual run with no import result is one error; the old night has 2 more');
    }

    public function testErrorsWithNoTextAreOneEntryWithAMissingMessage(): void
    {
        $items = $this->feed([$this->nightly(1, 'nlb', '2026-10-29 03:05:00', 2, null, null)], [])['items'];

        $this->assertSame([[null, 2]], array_map(static fn (array $item): array => [$item['message'], $item['count']], $items));
    }

    public function testOnlyFailedManualRunsCount(): void
    {
        $manuals = [
            $this->manual(1, 'nlb', '2026-10-29 11:00:00', ManualImport::DONE, 0, null, null, 'suggester broke'),
            $this->manual(2, 'nlb', '2026-10-29 11:30:00', ManualImport::IMPORTING, null, null, null),
            $this->manual(3, 'nlb', '2026-10-29 11:40:00', ManualImport::QUEUED, null, null, null),
        ];

        $this->assertSame([], $this->feed([], $manuals)['items']);
    }

    public function testARunThatDiedIsOneInterruptedEntry(): void
    {
        $dead = $this->manual(1, 'nlb', '2026-10-29 08:00:00', ManualImport::IMPORTING, null, null, null);
        $dead->updated_at = Carbon::now()->subMinutes(ManualImport::STALE_AFTER_MINUTES + 1);

        $items = $this->feed([], [$dead])['items'];

        $this->assertSame([['manual', 'The import was interrupted.', 1]], array_map(static fn (array $item): array => [$item['source'], $item['message'], $item['count']], $items));
    }

    public function testADeadRunThatHadImportedCleanlyIsStillOneError(): void
    {
        $dead = $this->manual(1, 'nlb', '2026-10-29 08:00:00', ManualImport::SUGGESTING, 0, null, null);
        $dead->updated_at = Carbon::now()->subMinutes(ManualImport::STALE_AFTER_MINUTES + 1);

        $items = $this->feed([], [$dead])['items'];

        $this->assertSame([['The import was interrupted.', 1]], array_map(static fn (array $item): array => [$item['message'], $item['count']], $items));
    }

    public function testTheRepeatsOfKeptMessagesAndTheCutOffOnesAreCountedOnce(): void
    {
        $event  = new ImportedTransactions('nlb.json', [], [], [0 => [...array_fill(0, 5, 'a'), ...array_map(static fn (int $i): string => 'error '.$i, range(1, 55)), 'a']], []);
        $kept   = RecordImportResult::errorMessages($event);
        $errors = RecordImportResult::summarize($event)['errors'];

        $items = $this->feed([$this->nightly(1, 'nlb', '2026-10-29 03:05:00', $errors, $kept)], [], limit: 100)['items'];

        $this->assertCount(51, $items);
        $this->assertSame(61, array_sum(array_column($items, 'count')), 'every error is in exactly one entry');
        $this->assertSame(['a', 6], [$items[0]['message'], $items[0]['count']]);
        $this->assertSame([null, 6], [$items[50]['message'], $items[50]['count']]);
    }

    public function testFiltersByConnectionAndSourceButTheBanksKeepEveryConnection(): void
    {
        $runs    = [$this->nightly(1, 'nlb', '2026-10-29 03:05:00', 1, [['message' => 'a', 'count' => 1]]), $this->nightly(2, 'abanka', '2026-10-29 03:06:00', 1, [['message' => 'b', 'count' => 1]])];
        $manuals = [$this->manual(1, 'nlb', '2026-10-29 10:00:00', 'failed', 1, [['message' => 'c', 'count' => 1]], 'c')];

        $feed = $this->feed($runs, $manuals, ['connection' => 'nlb', 'source' => 'nightly']);

        $this->assertSame(['a'], array_column($feed['items'], 'message'));
        $this->assertSame(['abanka', 'nlb'], array_column($feed['connections'], 'name'));
    }

    public function testSearchesTheMessagesAnyCaseAndDropsTheEntryWithoutText(): void
    {
        $run = $this->nightly(1, 'nlb', '2026-10-29 03:05:00', 9, [['message' => 'Bad IBAN: SI56', 'count' => 1], ['message' => 'Zero amount', 'count' => 1]]);

        $this->assertSame(['Bad IBAN: SI56'], array_column($this->feed([$run], [], ['q' => 'iban'])['items'], 'message'));
        $this->assertSame([], $this->feed([$run], [], ['q' => 'nothing like it'])['items']);
        $this->assertSame(['Bad IBAN: SI56', 'Zero amount', null], array_column($this->feed([$run], [], ['q' => '  '])['items'], 'message'), 'a blank search is no search');
    }

    public function testPagesTheEntries(): void
    {
        $run = $this->nightly(1, 'nlb', '2026-10-29 03:05:00', 5, array_map(static fn (int $i): array => ['message' => 'm'.$i, 'count' => 1], range(1, 5)));

        $first  = $this->feed([$run], [], limit: 2);
        $second = $this->feed([$run], [], page: 2, limit: 2);
        $last   = $this->feed([$run], [], page: 3, limit: 2);

        $this->assertSame([['m1', 'm2'], true, 5], [array_column($first['items'], 'message'), $first['has_more'], $first['total']]);
        $this->assertSame([['m3', 'm4'], true], [array_column($second['items'], 'message'), $second['has_more']]);
        $this->assertSame([['m5'], false], [array_column($last['items'], 'message'), $last['has_more']]);
    }

    public function testARemovedBankKeepsItsName(): void
    {
        $manual = $this->manual(1, 'gone', '2026-10-29 10:00:00', 'failed', 1, [['message' => 'x', 'count' => 1]], 'x');

        $feed = $this->feed([$this->nightly(1, 'gone', '2026-10-29 03:05:00', 1, [['message' => 'y', 'count' => 1]])], [$manual], banks: ['gone' => 'Old Bank']);

        $this->assertSame(['Old Bank', 'Old Bank'], array_column($feed['items'], 'bank'));
    }

    public function testASameSecondTieGoesToTheNewerRow(): void
    {
        $runs = [$this->nightly(1, 'nlb', '2026-10-29 03:05:00', 1, [['message' => 'one', 'count' => 1]]), $this->nightly(2, 'nlb', '2026-10-29 03:05:00', 1, [['message' => 'two', 'count' => 1]])];

        $this->assertSame(['two', 'one'], array_column($this->feed($runs, [])['items'], 'message'));
    }

    private function feed(array $runs, array $manuals, array $filter = [], int $page = 1, int $limit = 50, array $banks = self::BANKS): array
    {
        return ErrorFeed::build($runs, $manuals, $banks, $filter, $page, $limit);
    }

    private function nightly(int $id, string $connection, string $at, int $errors, ?array $messages, ?string $firstError = null): ImportRun
    {
        $run = new ImportRun(['connection_name' => $connection, 'ok' => 0 === $errors, 'errors' => $errors, 'first_error' => $firstError, 'error_messages' => $messages, 'created_at' => Carbon::parse($at)]);
        $run->id = $id;

        return $run;
    }

    private function manual(int $id, string $connection, string $at, string $status, ?int $errors, ?array $messages, ?string $error, ?string $suggestError = null): ManualImport
    {
        $manual = new ManualImport([
            'status'         => $status,
            'date_from'      => '2026-10-01',
            'date_to'        => '2026-10-28',
            'summary'        => null === $errors ? null : ['messages' => 0, 'warnings' => 0, 'errors' => $errors, 'first_error' => $error],
            'error'          => $error,
            'error_messages' => $messages,
            'suggest_error'  => $suggestError,
            'created_at'     => Carbon::parse($at),
            'updated_at'     => Carbon::parse($at),
        ]);
        $manual->id = $id;
        $manual->setRelation('connection', new Connection(['name' => $connection]));

        return $manual;
    }
}
