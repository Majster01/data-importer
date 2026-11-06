<?php

/*
 * SettingNamesTest.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace Tests\Feature\FireflyX;

use PHPUnit\Framework\Attributes\DataProvider;
use Tests\FireflyXTestCase;

/**
 * Settings renamed for Firefly Pocket (firefly-pocket-stack, Hostnames and
 * routing steps 4 and 5) still read their old name while a server sets only
 * that one, and the new name wins when both are set.
 */
final class SettingNamesTest extends FireflyXTestCase
{
    private const array RENAMED = [
        // config key  => [new name, old name]
        'callback_url' => ['FIREFLY_POCKET_CALLBACK_URL', 'FIREFLYX_CALLBACK_URL'],
        'app_url'      => ['FIREFLY_POCKET_APP_URL', 'FIREFLYX_PICO_URL'],
    ];

    protected function tearDown(): void
    {
        foreach (self::RENAMED as [$new, $old]) {
            $this->setEnv($new, null);
            $this->setEnv($old, null);
        }
        parent::tearDown();
    }

    /** @return array<string, array{string}> */
    public static function settings(): array
    {
        return ['callback_url' => ['callback_url'], 'app_url' => ['app_url']];
    }

    #[DataProvider('settings')]
    public function testTheNewNameWins(string $key): void
    {
        [$new, $old] = self::RENAMED[$key];
        $this->setEnv($new, 'https://new.example.test');
        $this->setEnv($old, 'https://old.example.test');

        $this->assertSame('https://new.example.test', $this->setting($key));
    }

    #[DataProvider('settings')]
    public function testTheOldNameStillWorksOnItsOwn(string $key): void
    {
        $this->setEnv(self::RENAMED[$key][1], 'https://old.example.test');

        $this->assertSame('https://old.example.test', $this->setting($key));
    }

    #[DataProvider('settings')]
    public function testNeitherIsEmpty(string $key): void
    {
        $this->assertSame('', $this->setting($key));
    }

    private function setting(string $key): string
    {
        return (string) (require base_path('config/fireflyx.php'))[$key];
    }

    private function setEnv(string $name, ?string $value): void
    {
        if (null === $value) {
            unset($_SERVER[$name], $_ENV[$name]);
            putenv($name);

            return;
        }
        $_SERVER[$name] = $value;
        $_ENV[$name]    = $value;
        putenv(sprintf('%s=%s', $name, $value));
    }
}
