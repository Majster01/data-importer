<?php

/*
 * RedirectUrlTest.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace Tests\Unit\FireflyX;

use App\FireflyX\EnableBanking\RedirectUrl;
use Tests\TestCase;

final class RedirectUrlTest extends TestCase
{
    public function testAsksForFireflyPocketsRegisteredUrl(): void
    {
        config(['fireflyx.callback_url' => 'https://app.example.test/eb-callback']);

        $this->assertSame('https://app.example.test/eb-callback', RedirectUrl::forImporterFlow());
    }

    public function testWithoutItAsksForItsOwnRoute(): void
    {
        config(['fireflyx.callback_url' => '']);

        $this->assertSame(route('eb-connect.callback'), RedirectUrl::forImporterFlow());
        $this->assertStringEndsWith('/eb-callback', RedirectUrl::forImporterFlow());
    }
}
