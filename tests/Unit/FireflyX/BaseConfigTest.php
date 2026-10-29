<?php

/*
 * BaseConfigTest.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace Tests\Unit\FireflyX;

use App\FireflyX\Import\BaseConfig;
use App\FireflyX\Import\ConfigException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class BaseConfigTest extends TestCase
{
    public function testAcceptsTheCommittedBase(): void
    {
        $base = BaseConfig::load(__DIR__.'/../../fixtures/fireflyx/base.json');

        $this->assertSame('eb', $base->values()['flow']);
    }

    public static function brokenBases(): array
    {
        return [
            'per-connection key' => [['accounts' => []], 'per-connection or date keys: accounts'],
            'window key'         => [['date_not_before' => '2026-01-01'], 'date_not_before'],
            'other flow'         => [['flow' => 'nordigen'], '"flow": "eb"'],
            'classic duplicates' => [['duplicate_detection_method' => 'classic'], '"cell"'],
        ];
    }

    #[DataProvider('brokenBases')]
    public function testRejectsUnsafeBases(array $override, string $message): void
    {
        $values = json_decode((string) file_get_contents(__DIR__.'/../../fixtures/fireflyx/base.json'), true);

        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage($message);
        BaseConfig::fromArray([...$values, ...$override]);
    }
}
