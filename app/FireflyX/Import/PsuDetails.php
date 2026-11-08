<?php

/*
 * PsuDetails.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace App\FireflyX\Import;

/**
 * What Pico tells us about the person who tapped "Import now". The bank only
 * wants a real, public address and a plain User-Agent; anything else is dropped
 * (the import still runs, just without that header).
 */
final class PsuDetails
{
    public const int MAX_USER_AGENT = 256;

    public static function ip(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $ip = trim($value);
        // GLOBAL_RANGE also refuses carrier-NAT (100.64.0.0/10) and documentation ranges.
        if (false === filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_GLOBAL_RANGE)) {
            return null;
        }

        return $ip;
    }

    public static function userAgent(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }
        $agent = trim($value);
        if ('' === $agent || strlen($agent) > self::MAX_USER_AGENT || !mb_check_encoding($agent, 'UTF-8') || 1 === preg_match('/[\x00-\x1f\x7f]/', $agent)) {
            return null;
        }

        return $agent;
    }
}
