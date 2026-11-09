<?php

/*
 * ManualImportTest.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace Tests\Feature\FireflyX;

use App\Events\ImportedTransactions;
use App\FireflyX\Import\ConfigImporter;
use App\FireflyX\Import\ManualImportLauncher;
use App\FireflyX\Models\Connection;
use App\FireflyX\Models\ManualImport;
use Carbon\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Tests\FireflyXTestCase;

/**
 * "Import now": POST a time frame → detached run → import with the saved
 * connection's config → category suggester over the same time frame.
 */
final class ManualImportTest extends FireflyXTestCase
{
    /** @var list<int> */
    private array $launched = [];

    private FakeImporter $importer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importer = new FakeImporter();
        $this->app->instance(ConfigImporter::class, $this->importer);
        $this->app->instance(ManualImportLauncher::class, new class($this) extends ManualImportLauncher {
            public function __construct(private readonly ManualImportTest $test) {}

            public function launch(ManualImport $run): void
            {
                $this->test->recordLaunch($run->id);
            }
        });
        config(['fireflyx.suggester_url' => 'http://suggester.test', 'fireflyx.suggester_token' => str_repeat('s', 40)]);
        Carbon::setTestNow('2026-10-29 12:00:00');
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function recordLaunch(int $id): void
    {
        $this->launched[] = $id;
    }

    public function testStartingQueuesARunAndLaunchesItDetached(): void
    {
        $this->connection('nlb');

        $this->start('nlb', '2026-09-01', '2026-09-30')
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'queued')
            ->assertJsonPath('data.date_from', '2026-09-01')
            ->assertJsonPath('data.date_to', '2026-09-30');

        $this->assertSame([ManualImport::first()->id], $this->launched);
        $this->getJson('/api/fireflyx/v1/connections/nlb', $this->auth())->assertJsonPath('data.manual_import.status', 'queued');
    }

    public function testRefusesWhatCannotBeImported(): void
    {
        $this->connection('nlb');
        $this->connection('gone', ['eb_status' => 'EXPIRED']);

        foreach ([
            ['nlb', '2026-09-30', '2026-09-01'],
            ['nlb', '2026-09-01', '2026-10-30'],
            ['nlb', '2026-02-31', '2026-03-05'],
            ['nlb', 'yesterday', '2026-10-29'],
            ['nlb', '', ''],
            ['gone', '2026-09-01', '2026-09-30'],
        ] as [$name, $from, $to]) {
            $this->start($name, $from, $to)->assertStatus(422);
        }
        $this->start('nope', '2026-09-01', '2026-09-30')->assertNotFound();
        $this->start('nlb', '2026-09-01', '2026-10-29')->assertStatus(202);

        $this->assertSame(1, ManualImport::count());
    }

    public function testTheUsersAddressAndUserAgentAreKeptButNeverShown(): void
    {
        $this->connection('nlb');

        $response = $this->postJson('/api/fireflyx/v1/connections/nlb/imports', [
            'date_from' => '2026-09-01', 'date_to' => '2026-09-30', 'psu_ip' => '8.8.8.8', 'psu_user_agent' => 'Pico/1.0',
        ], $this->auth())->assertStatus(202);

        $run = ManualImport::first();
        $this->assertSame(['8.8.8.8', 'Pico/1.0'], [$run->psu_ip, $run->psu_user_agent]);
        $this->assertStringNotContainsString('8.8.8.8', $response->getContent());
        $this->assertStringNotContainsString('Pico', $response->getContent());
        $this->getJson('/api/fireflyx/v1/connections/nlb', $this->auth())
            ->assertJsonPath('data.manual_import.status', 'queued')
            ->assertJsonMissingPath('data.manual_import.psu_ip')
            ->assertJsonMissingPath('data.manual_import.psu_user_agent');
    }

    public function testUnusableAddressesAreDroppedWithoutRefusingTheImport(): void
    {
        $this->connection('nlb');
        $logged = [];
        Log::listen(static function ($message) use (&$logged): void {
            $logged[] = $message->message;
        });

        foreach ([['192.168.1.20', null], ['junk', null], ['2606:4700:4700::1111', '2606:4700:4700::1111']] as [$given, $kept]) {
            ManualImport::query()->delete();
            $this->postJson('/api/fireflyx/v1/connections/nlb/imports', [
                'date_from' => '2026-09-01', 'date_to' => '2026-09-30', 'psu_ip' => $given, 'psu_user_agent' => str_repeat('a', 257),
            ], $this->auth())->assertStatus(202);

            $run = ManualImport::first();
            $this->assertSame([$kept, null], [$run->psu_ip, $run->psu_user_agent], $given);
        }
        $all = implode("\n", $logged);
        $this->assertStringContainsString('psu_ip was not usable', $all);
        $this->assertStringContainsString('psu_user_agent was not usable', $all);
        $this->assertStringNotContainsString('192.168.1.20', $all);
    }

    public function testWithoutTheFieldsTheRunIsSimplyUntagged(): void
    {
        $this->connection('nlb');

        $this->start('nlb', '2026-09-01', '2026-09-30')->assertStatus(202);

        $run = ManualImport::first();
        $this->assertSame([null, null], [$run->psu_ip, $run->psu_user_agent]);
    }

    public function testARunThatCannotBeLaunchedFailsAndForgetsTheUser(): void
    {
        $this->connection('nlb');
        $this->app->instance(ManualImportLauncher::class, new class extends ManualImportLauncher {
            public function launch(ManualImport $run): void
            {
                throw new RuntimeException('no php');
            }
        });

        $this->postJson('/api/fireflyx/v1/connections/nlb/imports', ['date_from' => '2026-09-01', 'date_to' => '2026-09-30', 'psu_ip' => '8.8.8.8', 'psu_user_agent' => 'Pico/1.0'], $this->auth())
            ->assertStatus(202)
            ->assertJsonPath('data.status', 'failed');

        $run = ManualImport::first();
        $this->assertSame([null, null], [$run->psu_ip, $run->psu_user_agent]);
    }

    public function testOnlyOneRunAtATime(): void
    {
        $this->connection('nlb');
        $this->connection('abanka');
        $this->start('nlb', '2026-09-01', '2026-09-30')->assertStatus(202);

        $this->start('abanka', '2026-09-01', '2026-09-30')->assertStatus(409);

        ManualImport::query()->update(['status' => ManualImport::DONE]);
        $this->start('abanka', '2026-09-01', '2026-09-30')->assertStatus(202);
    }

    public function testARunThatDiedIsShownFailedAndNoLongerBlocks(): void
    {
        $this->connection('nlb');
        $this->postJson('/api/fireflyx/v1/connections/nlb/imports', ['date_from' => '2026-09-01', 'date_to' => '2026-09-30', 'psu_ip' => '8.8.8.8', 'psu_user_agent' => 'Pico/1.0'], $this->auth())->assertStatus(202);
        $dead = ManualImport::first();
        ManualImport::query()->update(['status' => ManualImport::IMPORTING, 'updated_at' => Carbon::now()->subMinutes(ManualImport::STALE_AFTER_MINUTES + 1)]);

        $this->getJson('/api/fireflyx/v1/connections/nlb', $this->auth())
            ->assertJsonPath('data.manual_import.status', 'failed')
            ->assertJsonPath('data.manual_import.error', 'The import was interrupted.');
        $this->start('nlb', '2026-09-01', '2026-09-30')->assertStatus(202);
        $dead->refresh();
        $this->assertSame([null, null], [$dead->psu_ip, $dead->psu_user_agent], 'a run that died never wiped the details, so the next start does');
    }

    public function testImportsTheSavedConfigOverTheChosenTimeFrameThenSuggests(): void
    {
        $this->connection('nlb', ['import_since' => '2026-08-01']);
        Http::fake(['suggester.test/*' => Http::response(['written' => 3])]);
        $run = $this->queued('nlb', '2026-09-01', '2026-09-30');
        $this->importer->result = new ImportedTransactions('nlb.manual-1.json', [0 => ['a', 'b']], [], [], []);

        $this->artisan('fireflyx:manual-import', ['id' => $run->id])->assertSuccessful();

        // Same settings as the nightly config, only the time frame differs.
        $nightly = $this->renderNightly();
        $manual  = $this->importer->config;
        $this->assertSame('range', $manual['date_range']);
        $this->assertSame(['2026-09-01', '2026-09-30'], [$manual['date_not_before'], $manual['date_not_after']]);
        $window = ['date_range', 'date_range_number', 'date_range_unit', 'date_not_before', 'date_not_after'];
        $this->assertSame(array_diff_key($nightly, array_flip($window)), array_diff_key($manual, array_flip($window)));

        $run->refresh();
        $this->assertSame(ManualImport::DONE, $run->status);
        $this->assertSame(['messages' => 2, 'warnings' => 0, 'errors' => 0, 'first_error' => null], $run->summary);
        $this->assertSame(['written' => 3], $run->suggestions);
        $this->assertNotNull($run->finished_at);
        Http::assertSent(static fn ($request): bool => 'http://suggester.test/suggest' === $request->url()
            && ['since' => '2026-09-01', 'until' => '2026-09-30'] === $request->data()
            && 'Bearer '.str_repeat('s', 40) === $request->header('Authorization')[0]);

        $this->assertSame([], glob($this->importDir.'/manual/*'), 'the config is not left behind');
        $connection = Connection::where('name', 'nlb')->first();
        $this->assertNull($connection->last_import_at, "a manual import is not the night's import");
        $this->assertSame('2026-08-01', $connection->import_since->format('Y-m-d'), 'the first-import backfill is untouched');
    }

    public function testAManualRunIsTaggedWithTheUsersDetailsOnlyWhileItRuns(): void
    {
        $this->connection('nlb');
        Http::fake(['suggester.test/*' => Http::response(['written' => 0])]);
        config(['eb.add_import_ip_header' => false, 'eb.import_ip' => '127.0.0.1', 'eb.psu_user_agent' => null]);
        $run = $this->queued('nlb', '2026-09-01', '2026-09-30', ['psu_ip' => '8.8.8.8', 'psu_user_agent' => 'Pico/1.0']);
        $this->importer->result = new ImportedTransactions('x.json', [0 => ['a']], [], [], []);

        $this->artisan('fireflyx:manual-import', ['id' => $run->id])->assertSuccessful();

        $this->assertSame([true, '8.8.8.8', 'Pico/1.0'], $this->importer->psu);
        $this->assertSame([false, '127.0.0.1', null], [config('eb.add_import_ip_header'), config('eb.import_ip'), config('eb.psu_user_agent')]);
        $run->refresh();
        $this->assertSame([null, null], [$run->psu_ip, $run->psu_user_agent]);
    }

    public function testTheRunSaysWhatItSentWithoutSayingIt(): void
    {
        $this->connection('nlb');
        $logged = [];
        Log::listen(static function ($message) use (&$logged): void {
            $logged[] = $message->message;
        });
        $tagged   = $this->queued('nlb', '2026-09-01', '2026-09-30', ['psu_ip' => '8.8.8.8', 'psu_user_agent' => 'Pico/1.0']);
        $untagged = $this->queued('nlb', '2026-09-01', '2026-09-30');
        $this->importer->exitCode = 5;

        $this->artisan('fireflyx:manual-import', ['id' => $tagged->id])->assertFailed();
        $this->artisan('fireflyx:manual-import', ['id' => $untagged->id])->assertFailed();

        $all = implode("\n", $logged);
        $this->assertStringContainsString(sprintf('FireflyX: manual import %d psu_tagged: ip sent, user agent sent.', $tagged->id), $all);
        $this->assertStringContainsString(sprintf('FireflyX: manual import %d psu_tagged: ip missing, user agent missing.', $untagged->id), $all);
        $this->assertStringNotContainsString('8.8.8.8', $all);
        $this->assertStringNotContainsString('Pico/1.0', $all);
    }

    public function testARunWithoutTheUsersDetailsSendsNone(): void
    {
        $this->connection('nlb');
        config(['eb.add_import_ip_header' => false, 'eb.psu_user_agent' => null]);
        $run = $this->queued('nlb', '2026-09-01', '2026-09-30');
        $this->importer->exitCode = 5;

        $this->artisan('fireflyx:manual-import', ['id' => $run->id])->assertFailed();

        $this->assertSame([false, null], [$this->importer->psu[0], $this->importer->psu[2]]);
    }

    public function testImportErrorsFailTheRunButStillSuggest(): void
    {
        $this->connection('nlb');
        Http::fake(['suggester.test/*' => Http::response(['written' => 2])]);
        $run = $this->queued('nlb', '2026-09-01', '2026-09-30', ['psu_ip' => '8.8.8.8', 'psu_user_agent' => 'Pico/1.0']);
        $this->importer->result = new ImportedTransactions('nlb.manual-1.json', [], [], [3 => ['Enable Banking API error: 401']], []);

        $this->artisan('fireflyx:manual-import', ['id' => $run->id])->assertFailed();

        $this->assertDetailsGone($run);

        $run->refresh();
        $this->assertSame([ManualImport::FAILED, 'Enable Banking API error: 401'], [$run->status, $run->error]);
        $this->assertNotNull($run->finished_at);
        $this->assertSame(1, $run->summary['errors']);
        $this->assertSame([['message' => 'Enable Banking API error: 401', 'count' => 1]], $run->error_messages);
        $this->assertSame(['messages', 'warnings', 'errors', 'first_error'], array_keys($run->summary), 'the list is not part of the summary');
        $this->assertArrayNotHasKey('error_messages', $run->present());
        $this->assertSuggestedOverTheTimeFrame();
        $this->assertSame([ManualImport::FAILED, ['written' => 2], null], [$run->present()['status'], $run->present()['suggestions'], $run->present()['suggest_error']]);
    }

    public function testSuggesterTroubleAfterImportErrorsKeepsBothSaid(): void
    {
        $this->connection('nlb');
        Http::fake(['suggester.test/*' => Http::response(['error' => 'model API unreachable'], 502)]);
        $run = $this->queued('nlb', '2026-09-01', '2026-09-30');
        $this->importer->result = new ImportedTransactions('x.json', [], [], [3 => ['Enable Banking API error: 401']], []);

        $this->artisan('fireflyx:manual-import', ['id' => $run->id])->assertFailed();

        $run->refresh();
        $this->assertSame([ManualImport::FAILED, 'Enable Banking API error: 401', 'model API unreachable', null], [$run->status, $run->error, $run->suggest_error, $run->suggestions]);
    }

    public function testAnImporterThatThrowsStillSuggests(): void
    {
        $this->connection('nlb');
        Http::fake(['suggester.test/*' => Http::response(['written' => 1])]);
        $run = $this->queued('nlb', '2026-09-01', '2026-09-30', ['psu_ip' => '8.8.8.8', 'psu_user_agent' => 'Pico/1.0']);
        $this->importer->throw = new RuntimeException('consent expired');

        $this->artisan('fireflyx:manual-import', ['id' => $run->id])->assertFailed();

        $this->assertDetailsGone($run);
        $run->refresh();
        $this->assertSame([ManualImport::FAILED, 'consent expired', ['written' => 1], null], [$run->status, $run->error, $run->suggestions, $run->suggest_error]);
        $this->assertNotNull($run->finished_at);
        $this->assertSuggestedOverTheTimeFrame();
        $this->assertSame([], glob($this->importDir.'/manual/*'));
    }

    public function testAnImporterThatNeverReportsFailsTheRunButStillSuggests(): void
    {
        $this->connection('nlb');
        Http::fake(['suggester.test/*' => Http::response(['written' => 0])]);
        $run = $this->queued('nlb', '2026-09-01', '2026-09-30', ['psu_ip' => '8.8.8.8', 'psu_user_agent' => 'Pico/1.0']);
        $this->importer->exitCode = 5;

        $this->artisan('fireflyx:manual-import', ['id' => $run->id])->assertFailed();

        $this->assertDetailsGone($run);
        $run->refresh();
        $this->assertSame([ManualImport::FAILED, 'The importer could not run (exit code 5).', ['written' => 0], null], [$run->status, $run->error, $run->suggestions, $run->suggest_error]);
        $this->assertNull($run->error_messages, 'a failure with no import result is read from error');
        $this->assertSuggestedOverTheTimeFrame();
        $this->assertSame([], glob($this->importDir.'/manual/*'));
    }

    public function testNothingIsSuggestedWhenNoImportWasAttempted(): void
    {
        $this->connection('nlb');
        Http::fake();
        config(['fireflyx.base_config' => $this->importDir.'/missing.json']);
        $run = $this->queued('nlb', '2026-09-01', '2026-09-30');

        $this->artisan('fireflyx:manual-import', ['id' => $run->id])->assertFailed();

        $this->assertSame(0, $this->importer->calls);
        Http::assertNothingSent();
        $run->refresh();
        $this->assertSame(ManualImport::FAILED, $run->status);
        $this->assertNotNull($run->error);
        $this->assertNull($run->suggestions);
    }

    private function assertSuggestedOverTheTimeFrame(): void
    {
        Http::assertSent(static fn ($request): bool => 'http://suggester.test/suggest' === $request->url()
            && ['since' => '2026-09-01', 'until' => '2026-09-30'] === $request->data());
    }

    public function testSuggesterTroubleKeepsTheImportDone(): void
    {
        $this->connection('nlb');
        Http::fake(['suggester.test/*' => Http::response(['error' => 'model API unreachable'], 502)]);
        $run = $this->queued('nlb', '2026-09-01', '2026-09-30');
        $this->importer->result = new ImportedTransactions('x.json', [0 => ['a']], [], [], []);

        $this->artisan('fireflyx:manual-import', ['id' => $run->id])->assertSuccessful();

        $run->refresh();
        $this->assertSame([ManualImport::DONE, 'model API unreachable', null], [$run->status, $run->suggest_error, $run->suggestions]);
    }

    public function testASuggesterThatIsNotConfiguredIsSaid(): void
    {
        $this->connection('nlb');
        config(['fireflyx.suggester_url' => '']);
        $run = $this->queued('nlb', '2026-09-01', '2026-09-30');
        $this->importer->result = new ImportedTransactions('x.json', [0 => ['a']], [], [], []);

        $this->artisan('fireflyx:manual-import', ['id' => $run->id])->assertSuccessful();

        $this->assertSame([ManualImport::DONE, 'The category suggester is not configured.'], [$run->refresh()->status, $run->suggest_error]);
    }

    public function testARunStartsOnlyOnce(): void
    {
        $this->connection('nlb');
        Http::fake(['suggester.test/*' => Http::response(['written' => 0])]);
        $run = $this->queued('nlb', '2026-09-01', '2026-09-30');
        $this->importer->result = new ImportedTransactions('x.json', [0 => ['a']], [], [], []);

        $this->artisan('fireflyx:manual-import', ['id' => $run->id])->assertSuccessful();
        $this->artisan('fireflyx:manual-import', ['id' => $run->id])->assertFailed();

        $this->assertSame(1, $this->importer->calls);
    }

    private function renderNightly(): array
    {
        $this->artisan('fireflyx:render')->assertSuccessful();

        return json_decode((string) file_get_contents($this->importDir.'/nlb.json'), true);
    }

    private function start(string $name, string $from, string $to): \Illuminate\Testing\TestResponse
    {
        return $this->postJson("/api/fireflyx/v1/connections/{$name}/imports", ['date_from' => $from, 'date_to' => $to], $this->auth());
    }

    private function queued(string $name, string $from, string $to, array $psu = []): ManualImport
    {
        return Connection::where('name', $name)->firstOrFail()->manualImports()->create(['date_from' => $from, 'date_to' => $to, 'status' => ManualImport::QUEUED, ...$psu]);
    }

    private function assertDetailsGone(ManualImport $run): void
    {
        $run->refresh();
        $this->assertSame([null, null], [$run->psu_ip, $run->psu_user_agent]);
        $this->assertFalse(config('eb.add_import_ip_header'));
        $this->assertNull(config('eb.psu_user_agent'));
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

    private function auth(): array
    {
        return ['Authorization' => 'Bearer '.str_repeat('t', 40)];
    }
}

/**
 * Stands in for `importer:import`: remembers the config it was given.
 */
final class FakeImporter implements ConfigImporter
{
    public ?ImportedTransactions $result = null;
    public int $exitCode                 = 0;
    public int $calls                    = 0;
    public ?\Throwable $throw           = null;
    public array $config                 = [];
    /** @var array{0: mixed, 1: mixed, 2: mixed} eb.* config as the import saw it */
    public array $psu                    = [];

    public function import(string $configFile): array
    {
        ++$this->calls;
        if (null !== $this->throw) {
            throw $this->throw;
        }
        $this->psu    = [config('eb.add_import_ip_header'), config('eb.import_ip'), config('eb.psu_user_agent')];
        $this->config = json_decode((string) file_get_contents($configFile), true, 512, JSON_THROW_ON_ERROR);
        if (!str_contains($configFile, '/manual/') || !str_ends_with($configFile, '.json')) {
            throw new RuntimeException('manual imports must not be rendered where the nightly import looks');
        }

        return ['exit_code' => $this->exitCode, 'result' => $this->result];
    }
}
