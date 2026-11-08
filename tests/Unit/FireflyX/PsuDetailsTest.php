<?php

/*
 * PsuDetailsTest.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace Tests\Unit\FireflyX;

use App\FireflyX\Import\PsuDetails;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PsuDetailsTest extends TestCase
{
    public static function ips(): array
    {
        return [
            'public v4'       => ['8.8.8.8', '8.8.8.8'],
            'padded'          => [' 8.8.8.8 ', '8.8.8.8'],
            'public v6'       => ['2606:4700:4700::1111', '2606:4700:4700::1111'],
            'private'         => ['192.168.1.20', null],
            'private 10'      => ['10.0.0.5', null],
            'loopback'        => ['127.0.0.1', null],
            'loopback v6'     => ['::1', null],
            'link-local'      => ['169.254.1.1', null],
            'unique local v6' => ['fd00::1', null],
            'carrier NAT'     => ['100.64.1.1', null],
            'documentation'   => ['203.0.113.7', null],
            'junk'            => ['not an ip', null],
            'empty'           => ['', null],
            'not a string'    => [['8.8.8.8'], null],
            'null'            => [null, null],
        ];
    }

    #[DataProvider('ips')]
    public function testIp(mixed $given, ?string $expected): void
    {
        $this->assertSame($expected, PsuDetails::ip($given));
    }

    public static function agents(): array
    {
        return [
            'browser'        => ['Mozilla/5.0 (iPhone; CPU iPhone OS 18_0)', 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0)'],
            'padded'         => ['  Pico/1.0 ', 'Pico/1.0'],
            'at the cap'     => [str_repeat('a', 256), str_repeat('a', 256)],
            'over the cap'   => [str_repeat('a', 257), null],
            'empty'          => ['   ', null],
            'control char'   => ["Pico/1.0\r\nX-Evil: 1", null],
            'invalid UTF-8'  => ["Pico\xff", null],
            'not a string'   => [42, null],
            'null'           => [null, null],
        ];
    }

    #[DataProvider('agents')]
    public function testUserAgent(mixed $given, ?string $expected): void
    {
        $this->assertSame($expected, PsuDetails::userAgent($given));
    }
}
