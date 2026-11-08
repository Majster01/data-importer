<?php

/*
 * PsuHeadersTest.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace Tests\Unit\FireflyX;

use App\Services\EnableBanking\Request\Request;
use App\Services\Shared\Response\Response;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * Which PSU headers a bank request carries: none for the nightly import, the
 * tapping user's for a manual one, and never in the log.
 */
final class PsuHeadersTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        openssl_pkey_export($key, $pem);
        config([
            'eb.application_id'       => 'app-id',
            'eb.private_key'          => $pem,
            'eb.add_import_ip_header' => false,
            'eb.import_ip'            => '127.0.0.1',
            'eb.psu_user_agent'       => null,
        ]);
    }

    public function testTheNightlyImportSendsNoPsuHeaders(): void
    {
        $headers = $this->headers();

        $this->assertArrayNotHasKey('PSU-IP-Address', $headers);
        $this->assertArrayNotHasKey('PSU-User-Agent', $headers);
    }

    public function testAManualImportSendsTheUsersAddressAndUserAgent(): void
    {
        config(['eb.add_import_ip_header' => true, 'eb.import_ip' => '8.8.8.8', 'eb.psu_user_agent' => 'Pico/1.0']);

        $headers = $this->headers();

        $this->assertSame('8.8.8.8', $headers['PSU-IP-Address']);
        $this->assertSame('Pico/1.0', $headers['PSU-User-Agent']);
    }

    public function testUserAgentAloneIsSentAlone(): void
    {
        config(['eb.psu_user_agent' => 'Pico/1.0']);

        $headers = $this->headers();

        $this->assertArrayNotHasKey('PSU-IP-Address', $headers);
        $this->assertSame('Pico/1.0', $headers['PSU-User-Agent']);
    }

    public function testAnAddressAloneIsSentAlone(): void
    {
        config(['eb.add_import_ip_header' => true, 'eb.import_ip' => '8.8.8.8']);

        $headers = $this->headers();

        $this->assertSame('8.8.8.8', $headers['PSU-IP-Address']);
        $this->assertArrayNotHasKey('PSU-User-Agent', $headers);
    }

    public function testTheUsersDetailsStayOutOfTheLog(): void
    {
        config(['eb.add_import_ip_header' => true, 'eb.import_ip' => '8.8.8.8', 'eb.psu_user_agent' => 'Pico/1.0']);
        $logged = [];
        Log::listen(static function ($message) use (&$logged): void {
            $logged[] = ['message' => $message->message, 'context' => $message->context];
        });

        $this->headers();

        $final = array_values(array_filter($logged, static fn (array $line): bool => str_starts_with($line['message'], 'Call to getHeaders() with final headers')));
        $this->assertCount(1, $final);
        $this->assertSame('REDACTED', $final[0]['context']['PSU-IP-Address']);
        $this->assertSame('REDACTED', $final[0]['context']['PSU-User-Agent']);
        $all = json_encode($logged);
        $this->assertStringNotContainsString('8.8.8.8', $all);
        $this->assertStringNotContainsString('Pico/1.0', $all);
    }

    private function headers(): array
    {
        return (new class extends Request {
            public function get(): Response
            {
                throw new \LogicException('not used');
            }

            public function post(): Response
            {
                throw new \LogicException('not used');
            }

            public function headers(): array
            {
                return $this->getHeaders();
            }
        })->headers();
    }
}
