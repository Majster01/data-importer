<?php

/*
 * ImportNoticeTest.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace Tests\Feature\FireflyX;

use App\Events\ImportedTransactions;
use App\FireflyX\Import\ImportNotice;
use App\FireflyX\Models\Connection;
use Carbon\Carbon;
use Tests\FireflyXTestCase;

/**
 * The words the nightly run sends to the phone, and the --json report that
 * hands them to the shell.
 */
final class ImportNoticeTest extends FireflyXTestCase
{
    public function testASuccessNamesTheAccountAndCountsTheTransactions(): void
    {
        $connection = $this->connection('nlb', ['bank_name' => 'NLB d.d.'], [['name' => 'NLB Current']]);

        $notice = ImportNotice::for($connection, $this->summary(messages: 3));

        $this->assertSame('import.success', $notice['event']);
        $this->assertSame('NLB Current import succeeded', $notice['title']);
        $this->assertSame('3 new transactions imported.', $notice['body']);
        $this->assertSame(3, $notice['count']);
        $this->assertSame('1 new transaction imported.', ImportNotice::for($connection, $this->summary(messages: 1))['body']);
    }

    public function testNothingNewIsASuccessWithAZeroCount(): void
    {
        $connection = $this->connection('nlb', ['bank_name' => 'NLB d.d.'], [['name' => 'NLB Current']]);

        $notice = ImportNotice::for($connection, $this->summary());

        $this->assertSame('import.success', $notice['event']);
        $this->assertSame(0, $notice['count']);
        $this->assertSame('Nothing new since the last import.', $notice['body']);
    }

    public function testSeveralAccountsAreNamedAfterTheBank(): void
    {
        $connection = $this->connection('nlb', ['bank_name' => 'NLB d.d.'], [['name' => 'NLB Current'], ['name' => 'NLB Savings']]);

        $this->assertSame('NLB d.d. import succeeded', ImportNotice::for($connection, $this->summary(messages: 2))['title']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function failures(): array
    {
        return [
            'consent ended'    => ['[a112]: the EUA has expired', 'The consent for NLB d.d. has ended'],
            'bank side failure' => ['Enable Banking API error: Client error: `GET https://api.enablebanking.com/accounts/x/details` resulted in a `400 Bad Request` response: {"code":400,"message":"Error interacting with ASPSP","error":"ASPSP_ERROR"}', 'had a problem on its side'],
            'rate limited'     => ['The bank answered 429 Too Many Requests', 'limited how often'],
            'bank refused'     => ['Enable Banking API error: 401', 'Enable Banking refused the request'],
            'download failed'  => ['[eb001]: Could not download from Enable Banking: timeout', 'could not be downloaded'],
            'firefly refused'  => ['[a116]: Submission HTTP error: 422', 'Firefly III did not accept'],
            'something else'   => ['Exception: boom', 'finished with 2 errors'],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('failures')]
    public function testAFailureSaysWhatHappenedWhatToDoAndWhereTheErrorIs(string $firstError, string $expected): void
    {
        $connection = $this->connection('nlb', ['bank_name' => 'NLB d.d.'], [['name' => 'NLB Current']]);

        $notice = ImportNotice::for($connection, $this->summary(errors: 2, firstError: $firstError));

        $this->assertSame('import.failure', $notice['event']);
        $this->assertSame('NLB Current import failed', $notice['title']);
        $this->assertStringContainsString($expected, $notice['body']);
        $this->assertStringContainsString('Exact error: importer logs.', $notice['body']);
        $this->assertStringNotContainsString($firstError, $notice['body'], 'the raw error stays in the logs');
        $this->assertLessThanOrEqual(300, mb_strlen($notice['body']));
        $this->assertSame('/bank-connections', $notice['path']);
        $this->assertNull($notice['count']);
    }

    public function testABankSideFailureIsTheLastErrorInPlainWords(): void
    {
        $raw = 'Enable Banking API error: Client error: 400 {&#34;message&#34;:&#34;Error interacting with ASPSP&#34;,&#34;detail&#34;:{&#34;message&#34;:&#34;Internal server error&#34;}}';

        $summary = \App\FireflyX\Listeners\RecordImportResult::summarize(new ImportedTransactions('otp.json', [], [], [0 => [$raw]], []));

        $this->assertSame(1, $summary['errors']);
        $this->assertSame(\App\FireflyX\Import\BankSideFailure::SUMMARY, $summary['first_error']);
        $connection = $this->connection('otp', ['bank_name' => 'OTP banka'], [['name' => 'OTP Main']]);
        $this->assertStringContainsString('OTP banka had a problem on its side', ImportNotice::for($connection, $summary)['body']);
    }

    public function testAConnectionThatWasNotImportedIsAFailure(): void
    {
        $connection = $this->connection('nlb', ['bank_name' => 'NLB d.d.'], [['name' => 'NLB Current']]);

        $notice = ImportNotice::for($connection, null);

        $this->assertSame('import.failure', $notice['event']);
        $this->assertStringContainsString('did not import NLB d.d. at all', $notice['body']);
    }

    public function testTheReportHandsTheNoticesToTheShell(): void
    {
        $this->connection('nlb', ['bank_name' => 'NLB d.d.'], [['name' => 'NLB Current']]);
        $this->connection('abanka', ['bank_name' => 'Abanka'], [['name' => 'Abanka Main']]);
        $this->connection('revolut', ['bank_name' => 'Revolut'], [['name' => 'Revolut EUR']]);
        $started = Carbon::now()->subMinute()->toIso8601String();

        event(new ImportedTransactions('nlb.json', [0 => ['Created 1', 'Created 2']], [], [], []));
        event(new ImportedTransactions('abanka.json', [], [], [0 => ['[a112]: expired']], []));

        $exit = \Illuminate\Support\Facades\Artisan::call('fireflyx:connections:report', ['--since' => $started, '--json' => true]);

        $this->assertSame(1, $exit);
        $rows = json_decode(\Illuminate\Support\Facades\Artisan::output(), true);
        $byName = array_column($rows, 'notice', 'name');
        $this->assertSame('import.failure', $byName['abanka']['event']);
        $this->assertSame('import.success', $byName['nlb']['event']);
        $this->assertSame(2, $byName['nlb']['count']);
        $this->assertSame('Revolut EUR import failed', $byName['revolut']['title']);
    }

    private function summary(int $messages = 0, int $errors = 0, ?string $firstError = null): array
    {
        return ['messages' => $messages, 'warnings' => 0, 'errors' => $errors, 'first_error' => $firstError];
    }

    /**
     * @param list<array<string, mixed>> $accounts
     */
    private function connection(string $name, array $attributes, array $accounts): Connection
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
        foreach ($accounts as $i => $account) {
            $connection->accounts()->create(['uid' => 'uid-'.$name.$i, 'firefly_account_id' => 10 + $i, ...$account]);
        }

        return $connection->load('accounts');
    }
}
