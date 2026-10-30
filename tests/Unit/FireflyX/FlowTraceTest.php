<?php

/*
 * FlowTraceTest.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace Tests\Unit\FireflyX;

use App\FireflyX\EnableBanking\FlowTrace;
use Tests\TestCase;

final class FlowTraceTest extends TestCase
{
    private const string IBAN = 'SI56191000000123438';
    private const array SESSION = [
        'session_id'    => 'abcdef0123456789',
        'status'        => 'AUTHORIZED',
        'psu_type'      => 'personal',
        'aspsp'         => ['name' => 'OTP banka', 'country' => 'SI'],
        'access'        => ['valid_until' => '2027-01-01T00:00:00Z'],
        'accounts'      => [['uid' => 'u1', 'account_id' => ['iban' => self::IBAN], 'name' => 'Jane Doe', 'currency' => 'EUR']],
        'accounts_data' => [['uid' => 'u1', 'identification_hash' => 'h']],
        'surprise'      => 'x',
    ];

    public function testIsOffByDefault(): void
    {
        $this->assertFalse(FlowTrace::enabled());
        $this->assertFalse(FlowTrace::raw());
    }

    public function testRawNeedsTheTraceToo(): void
    {
        config(['fireflyx.eb_trace' => false, 'fireflyx.eb_trace_raw' => true]);
        $this->assertFalse(FlowTrace::raw());
    }

    public function testASessionIsDescribedWithoutIbansOrNames(): void
    {
        config(['fireflyx.eb_trace' => true, 'fireflyx.eb_trace_raw' => false]);

        $described = FlowTrace::describeSession(self::SESSION);
        $text      = json_encode($described, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString(self::IBAN, $text);
        $this->assertStringNotContainsString('Jane', $text);
        $this->assertSame(1, $described['accounts']['count']);
        $this->assertSame('present, 8 characters', $described['accounts']['items'][0]['name']);
        $this->assertSame('SI56…3438 (19)', $described['accounts']['items'][0]['iban']);
        $this->assertSame('x', $described['other']['surprise'], 'unknown keys are shown');
        $this->assertSame('abcdef01…', $described['session_id']);
        $this->assertArrayNotHasKey('raw', $described);
    }

    public function testAnEmptyAccountListIsShownAsEmptyNotMissing(): void
    {
        $described = FlowTrace::describeSession(['session_id' => 's', 'accounts' => [], 'status' => 'AUTHORIZED']);

        $this->assertSame(['count' => 0, 'items' => []], $described['accounts']);
        $this->assertSame('missing', $described['accounts_data']);
    }

    public function testRawModeAddsTheResponseButNotTheSessionId(): void
    {
        config(['fireflyx.eb_trace' => true, 'fireflyx.eb_trace_raw' => true]);

        $described = FlowTrace::describeSession(self::SESSION);

        $this->assertSame(self::IBAN, $described['raw']['accounts'][0]['account_id']['iban']);
        $this->assertSame('abcdef01…', $described['raw']['session_id']);
    }

    public function testTheCallbackQueryKeepsTheErrorAndHidesCodeAndState(): void
    {
        $described = FlowTrace::describeCallbackQuery([
            'state' => 'fx-0123456789abcdef', 'code' => 'secret-code', 'error' => 'access_denied', 'error_description' => 'Cancelled by user',
        ]);

        $this->assertSame('present, 11 characters', $described['code']);
        $this->assertSame('fx-012…', $described['state']);
        $this->assertSame('access_denied', $described['error']);
        $this->assertSame('Cancelled by user', $described['error_description']);
    }
}
