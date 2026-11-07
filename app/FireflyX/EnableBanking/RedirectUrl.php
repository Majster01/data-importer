<?php

/*
 * RedirectUrl.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace App\FireflyX\EnableBanking;

/**
 * Where Enable Banking sends the browser back after the importer's own flow,
 * started from its pages rather than from Firefly Pocket.
 *
 * Behind firefly-pocket-stack, the one redirect URL registered with Enable
 * Banking is Firefly Pocket's (fireflyx.callback_url, e.g.
 * https://app.example.com/eb-callback), and Caddy carries a callback with this
 * flow's state (the import job's UUID) on to these pages, wherever they are
 * served: under /importer/ on the console's host (Hostnames and routing step
 * 7). Asking for this route on the host the pages were opened on, as upstream
 * does, would ask for an address Enable Banking does not know. Without that
 * setting, upstream's own.
 */
final class RedirectUrl
{
    public static function forImporterFlow(): string
    {
        $registered = (string) config('fireflyx.callback_url');

        return '' !== $registered ? $registered : route('eb-connect.callback');
    }
}
