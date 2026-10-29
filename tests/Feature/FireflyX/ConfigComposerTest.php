<?php

/*
 * ConfigComposerTest.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace Tests\Feature\FireflyX;

use App\FireflyX\Import\BaseConfig;
use App\FireflyX\Import\ConfigComposer;
use App\FireflyX\Models\Connection;
use Carbon\Carbon;
use Tests\FireflyXTestCase;

final class ConfigComposerTest extends FireflyXTestCase
{
    /**
     * The guarantee behind the whole design: base ⊕ connection ⊕ window is the
     * very config that was hand-exported and imported nightly before.
     */
    public function testComposesExactlyTheHandExportedConfig(): void
    {
        $exported   = $this->fixture('exported-nlb.json');
        $connection = $this->nlbConnection();
        $composer   = new ConfigComposer(BaseConfig::load((string) config('fireflyx.base_config')), 5);

        $composed = $composer->compose($connection, Carbon::parse('2026-10-29'));

        ksort($composed);
        ksort($exported);
        $this->assertSame($exported, $composed);
    }

    public function testImportSinceReachesBackOnlyBeyondTheRollingWindow(): void
    {
        $connection = $this->nlbConnection();
        $composer   = new ConfigComposer(BaseConfig::load((string) config('fireflyx.base_config')), 5);
        $today      = Carbon::parse('2026-10-29');

        $connection->import_since = Carbon::parse('2026-09-01');
        $this->assertSame('2026-09-01', $composer->notBefore($connection, $today)->format('Y-m-d'));

        $connection->import_since = Carbon::parse('2026-10-28');
        $this->assertSame('2026-10-24', $composer->notBefore($connection, $today)->format('Y-m-d'));
    }

    public function testSkippedAccountsAreLeftOut(): void
    {
        $connection = $this->nlbConnection();
        $connection->accounts()->create(['uid' => 'skipped-uid', 'firefly_account_id' => null]);
        $composer   = new ConfigComposer(BaseConfig::load((string) config('fireflyx.base_config')), 5);

        $composed = $composer->compose($connection->load('accounts'), Carbon::parse('2026-10-29'));

        $this->assertSame(['00000000-0000-4000-8000-00000000cccc' => 1], $composed['accounts']);
    }

    private function nlbConnection(): Connection
    {
        $connection             = new Connection([
            'name'         => 'nlb',
            'lifecycle'    => Connection::LIFECYCLE_READY,
            'bank_name'    => 'NLB',
            'bank_country' => 'SI',
            'session_id'   => '00000000-0000-4000-8000-00000000aaaa',
            'auth_id'      => '00000000-0000-4000-8000-00000000bbbb',
        ]);
        $connection->created_at = Carbon::parse('2026-08-31T22:03:58+02:00');
        $connection->save();
        $connection->accounts()->create(['uid' => '00000000-0000-4000-8000-00000000cccc', 'firefly_account_id' => 1]);

        return $connection->load('accounts');
    }
}
