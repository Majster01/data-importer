<?php

/*
 * RecordImportResultTest.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace Tests\Unit\FireflyX;

use App\Events\ImportedTransactions;
use App\FireflyX\Listeners\RecordImportResult;
use Tests\TestCase;

final class RecordImportResultTest extends TestCase
{
    public function testNoErrorsNoMessages(): void
    {
        $this->assertSame([], RecordImportResult::errorMessages($this->event([])));
    }

    public function testKeepsEveryMessageInTheOrderTheyHappened(): void
    {
        $messages = RecordImportResult::errorMessages($this->event([0 => ['first'], 3 => ['second', 'third']]));

        $this->assertSame([['message' => 'first', 'count' => 1], ['message' => 'second', 'count' => 1], ['message' => 'third', 'count' => 1]], $messages);
    }

    public function testIdenticalMessagesAreCountedOnce(): void
    {
        $messages = RecordImportResult::errorMessages($this->event([0 => ['same', 'other', 'same'], 1 => ['same']]));

        $this->assertSame([['message' => 'same', 'count' => 3], ['message' => 'other', 'count' => 1]], $messages);
    }

    public function testKeepsOnlyTheFirstFiftyDistinctMessagesButSummaryKeepsTheTrueCount(): void
    {
        $errors = array_map(static fn (int $i): string => 'error '.$i, range(1, 60));
        $event  = $this->event([0 => $errors]);

        $messages = RecordImportResult::errorMessages($event);

        $this->assertCount(50, $messages);
        $this->assertSame('error 1', $messages[0]['message']);
        $this->assertSame('error 50', $messages[49]['message']);
        $this->assertSame(60, RecordImportResult::summarize($event)['errors']);
    }

    public function testCutsByCharactersNotBytes(): void
    {
        $messages = RecordImportResult::errorMessages($this->event([0 => [str_repeat('č', 1500)]]));

        $this->assertSame(1000, mb_strlen($messages[0]['message']));
        $this->assertNotFalse(json_encode($messages));
    }

    public function testBytesThatAreNotUtf8DoNotBreakTheJson(): void
    {
        $messages = RecordImportResult::errorMessages($this->event([0 => ["bad \xB1 byte"]]));

        $this->assertNotFalse(json_encode($messages));
    }

    public function testNothingToDownloadIsNotAnError(): void
    {
        $nothing = '[eb003]: No transactions were downloaded from Enable Banking.';

        $this->assertSame([], RecordImportResult::errorMessages($this->event([0 => [$nothing]])));
        $this->assertSame([['message' => 'real', 'count' => 1]], RecordImportResult::errorMessages($this->event([0 => [$nothing, 'real']])));
        $this->assertSame(1, RecordImportResult::summarize($this->event([0 => [$nothing, 'real']]))['errors']);
    }

    public function testSummaryStillCarriesOnlyTheFirstErrorAndTheCounts(): void
    {
        $summary = RecordImportResult::summarize($this->event([0 => ['a', 'b']], [0 => ['x']]));

        $this->assertSame(['messages' => 1, 'warnings' => 0, 'errors' => 2, 'first_error' => 'a'], $summary);
    }

    private function event(array $errors, array $messages = []): ImportedTransactions
    {
        return new ImportedTransactions('nlb.json', $messages, [], $errors, []);
    }
}
