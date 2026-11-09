<?php

/*
 * ErrorDayTest.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace Tests\Feature\FireflyX;

use App\FireflyX\Http\ErrorDay;
use App\FireflyX\Models\Connection;
use App\FireflyX\Models\ImportRun;
use App\FireflyX\Models\ManualImport;
use Carbon\Carbon;
use Tests\FireflyXTestCase;

/**
 * Built from unsaved rows: ErrorDay does no queries (the models only need the schema).
 */
final class ErrorDayTest extends FireflyXTestCase
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

    public function testAnEmptyDayHasNothing(): void
    {
        $day = ErrorDay::build(Carbon::parse('2026-10-29'), [], [], self::BANKS);

        $this->assertSame(['date' => '2026-10-29', 'count' => 0, 'connections' => [], 'items' => []], $day);
    }

    public function testListsNightlyAndManualErrorsNewestFirst(): void
    {
        $runs    = [$this->nightly(1, 'nlb', '2026-10-29 03:05:00', 2, [['message' => 'a', 'count' => 2]])];
        $manuals = [$this->manual(1, 'abanka', '2026-10-29 10:00:00', 'failed', 1, [['message' => 'b', 'count' => 1]], 'b')];

        $day = ErrorDay::build(Carbon::parse('2026-10-29'), $runs, $manuals, self::BANKS);

        $this->assertSame(3, $day['count']);
        $this->assertSame([
            ['at' => '2026-10-29T10:00:00+01:00', 'source' => 'manual', 'connection' => 'abanka', 'bank' => 'Abanka', 'messages' => [['message' => 'b', 'count' => 1]], 'more' => 0],
            ['at' => '2026-10-29T03:05:00+01:00', 'source' => 'nightly', 'connection' => 'nlb', 'bank' => 'NLB', 'messages' => [['message' => 'a', 'count' => 2]], 'more' => 0],
        ], $day['items']);
        $this->assertSame([['name' => 'abanka', 'bank' => 'Abanka'], ['name' => 'nlb', 'bank' => 'NLB']], $day['connections']);
    }

    public function testRunsThatHadNoErrorsAreLeftOut(): void
    {
        $day = ErrorDay::build(Carbon::parse('2026-10-29'), [$this->nightly(1, 'nlb', '2026-10-29 03:05:00', 0, null)], [], self::BANKS);

        $this->assertSame([], $day['items']);
    }

    public function testMoreIsWhatTheListDoesNotShow(): void
    {
        $run = $this->nightly(1, 'nlb', '2026-10-29 03:05:00', 120, [['message' => 'a', 'count' => 30], ['message' => 'b', 'count' => 20]]);

        $day = ErrorDay::build(Carbon::parse('2026-10-29'), [$run], [], self::BANKS);

        $this->assertSame(70, $day['items'][0]['more']);
        $this->assertSame(120, $day['count']);
    }

    public function testRowsFromBeforeTheListFallBackToTheFirstError(): void
    {
        $runs    = [$this->nightly(1, 'nlb', '2026-10-29 03:05:00', 3, null, 'old first error')];
        $manuals = [$this->manual(1, 'abanka', '2026-10-29 10:00:00', 'failed', null, null, 'old manual error')];

        $day = ErrorDay::build(Carbon::parse('2026-10-29'), $runs, $manuals, self::BANKS);

        $this->assertSame([['message' => 'old manual error', 'count' => 1]], $day['items'][0]['messages']);
        $this->assertSame(0, $day['items'][0]['more'], 'a manual run with no import result is one error');
        $this->assertSame([['message' => 'old first error', 'count' => 1]], $day['items'][1]['messages']);
        $this->assertSame(2, $day['items'][1]['more']);
    }

    public function testErrorsWithNoTextStillSayHowManyThereWere(): void
    {
        $day = ErrorDay::build(Carbon::parse('2026-10-29'), [$this->nightly(1, 'nlb', '2026-10-29 03:05:00', 2, null, null)], [], self::BANKS);

        $this->assertSame([], $day['items'][0]['messages']);
        $this->assertSame(2, $day['items'][0]['more']);
    }

    public function testOnlyFailedManualRunsCount(): void
    {
        $manuals = [
            $this->manual(1, 'nlb', '2026-10-29 11:00:00', ManualImport::DONE, 0, null, null, 'suggester broke'),
            $this->manual(2, 'nlb', '2026-10-29 11:30:00', ManualImport::IMPORTING, null, null, null),
            $this->manual(3, 'nlb', '2026-10-29 11:40:00', ManualImport::QUEUED, null, null, null),
        ];

        $day = ErrorDay::build(Carbon::parse('2026-10-29'), [], $manuals, self::BANKS);

        $this->assertSame([], $day['items']);
    }

    public function testARunThatDiedIsListedAsInterrupted(): void
    {
        $dead = $this->manual(1, 'nlb', '2026-10-29 08:00:00', ManualImport::IMPORTING, null, null, null);
        $dead->updated_at = Carbon::now()->subMinutes(ManualImport::STALE_AFTER_MINUTES + 1);

        $day = ErrorDay::build(Carbon::parse('2026-10-29'), [], [$dead], self::BANKS);

        $this->assertSame([['message' => 'The import was interrupted.', 'count' => 1]], $day['items'][0]['messages']);
    }

    public function testFiltersByConnectionButKeepsAllConnectionsForTheFilter(): void
    {
        $runs = [$this->nightly(1, 'nlb', '2026-10-29 03:05:00', 1, [['message' => 'a', 'count' => 1]]), $this->nightly(2, 'abanka', '2026-10-29 03:06:00', 4, [['message' => 'b', 'count' => 4]])];

        $day = ErrorDay::build(Carbon::parse('2026-10-29'), $runs, [], self::BANKS, 'nlb');

        $this->assertSame(['nlb'], array_column($day['items'], 'connection'));
        $this->assertSame(1, $day['count']);
        $this->assertSame(['abanka', 'nlb'], array_column($day['connections'], 'name'));
    }

    public function testARemovedBankKeepsItsNameAndAManualRunOfOneToo(): void
    {
        $manual = $this->manual(1, 'gone', '2026-10-29 10:00:00', 'failed', 1, [['message' => 'x', 'count' => 1]], 'x');

        $day = ErrorDay::build(Carbon::parse('2026-10-29'), [$this->nightly(1, 'gone', '2026-10-29 03:05:00', 1, [['message' => 'y', 'count' => 1]])], [$manual], ['gone' => 'Old Bank']);

        $this->assertSame(['Old Bank', 'Old Bank'], array_column($day['items'], 'bank'));
    }

    public function testASameSecondTieGoesToTheNewerRow(): void
    {
        $runs = [$this->nightly(1, 'nlb', '2026-10-29 03:05:00', 1, [['message' => 'one', 'count' => 1]]), $this->nightly(2, 'nlb', '2026-10-29 03:05:00', 1, [['message' => 'two', 'count' => 1]])];

        $day = ErrorDay::build(Carbon::parse('2026-10-29'), $runs, [], self::BANKS);

        $this->assertSame(['two', 'one'], array_map(static fn (array $item): string => $item['messages'][0]['message'], $day['items']));
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
