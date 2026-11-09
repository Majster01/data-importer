<?php

/*
 * TransactionDateTest.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace Tests\Unit\Services\EnableBanking;

use App\Services\EnableBanking\Model\Transaction;
use Carbon\Carbon;
use Tests\TestCase;

final class TransactionDateTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function transaction(array $dates): Transaction
    {
        return Transaction::fromArray(array_merge([
            'entry_reference'    => 'ref-1',
            'transaction_amount' => ['amount' => '5.00', 'currency' => 'EUR'],
        ], $dates));
    }

    public function testTransactionDateWinsOverBookingAndValueDate(): void
    {
        $t = $this->transaction(['transaction_date' => '2026-11-01', 'booking_date' => '2026-11-03', 'value_date' => '2026-11-02']);

        $this->assertSame('transaction_date', $t->getDateSource());
        $this->assertSame('2026-11-01', $t->getDate()->format('Y-m-d'));
    }

    public function testBookingDateIsUsedWhenTheBankSendsNoTransactionDate(): void
    {
        $t = $this->transaction(['booking_date' => '2026-11-03', 'value_date' => '2026-11-02']);

        $this->assertSame('booking_date', $t->getDateSource());
        $this->assertSame('2026-11-03', $t->getDate()->format('Y-m-d'));
    }

    public function testValueDateIsUsedWhenItIsTheOnlyOne(): void
    {
        $t = $this->transaction(['value_date' => '2026-11-02']);

        $this->assertSame('value_date', $t->getDateSource());
        $this->assertSame('2026-11-02', $t->getDate()->format('Y-m-d'));
    }

    public function testNoDatesFallsBackToNow(): void
    {
        Carbon::setTestNow('2026-11-09 10:00:00');
        $t = $this->transaction([]);

        $this->assertSame('now', $t->getDateSource());
        $this->assertSame('2026-11-09', $t->getDate()->format('Y-m-d'));
    }

    public function testRawDatesKeepWhatTheBankSentNotTheFallback(): void
    {
        $t = $this->transaction(['transaction_date' => '2026-11-01T23:30:00+01:00', 'booking_date' => null]);

        $this->assertNull($t->rawDates['booking_date']);
        $this->assertNull($t->rawDates['value_date']);
        $this->assertSame('2026-11-01T23:30:00+01:00', $t->rawDates['transaction_date']);
    }
}
