<?php

/*
 * fireflyx.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

return [
    // Shared secret for /api/fireflyx/v1. Pico's backend sends it as a Bearer
    // token; anything shorter than 32 characters disables the API entirely.
    'api_token'      => env('FIREFLYX_API_TOKEN', ''),

    // The one import config every connection shares (committed in FireflyX as
    // config/importer-base.json, mounted read-only).
    'base_config'    => env('FIREFLYX_BASE_CONFIG', '/fireflyx/base.json'),

    // Where fireflyx:render writes one <name>.json per connection for /autoimport.
    'import_dir'     => env('FIREFLYX_IMPORT_DIR', '/import'),

    // Every connection is fetched from today minus this many days. Banks book
    // late; duplicate detection discards the overlap.
    'lookback_days'  => (int) env('FIREFLYX_LOOKBACK_DAYS', 5),

    // A connection is "expiring" this many days before its consent ends, and
    // fireflyx:connections:refresh --warn-at alerts on these days left.
    'warn_days'      => (int) env('FIREFLYX_WARN_DAYS', 14),

    // GET /connections refreshes a connection from Enable Banking when its last
    // check is older than this.
    'refresh_after'  => (int) env('FIREFLYX_REFRESH_AFTER_MINUTES', 60),

    // The redirect URL registered with Enable Banking: upstream's own callback
    // on the public importer host, e.g. https://import.example.com/eb-callback.
    'callback_url'   => env('FIREFLYX_CALLBACK_URL', ''),

    // Where the user lands after the bank: Pico's public URL. Only a path from
    // the request is appended, so this is the one possible redirect target.
    'pico_url'       => env('FIREFLYX_PICO_URL', ''),

    // How long a started consent may take before its state stops working.
    'auth_ttl'       => (int) env('FIREFLYX_AUTH_TTL_MINUTES', 15),

    // The category suggester's trigger, run after a manual import over the same
    // time frame (see services/category-suggester in FireflyX). Reached on the
    // internal network only. Without a URL a manual import still runs and says
    // that its suggestions were not made.
    'suggester_url'     => env('FIREFLYX_SUGGESTER_URL', ''),
    'suggester_token'   => env('FIREFLYX_SUGGESTER_TOKEN', ''),
    // Seconds to wait for it: it makes one model call per group of transactions.
    'suggester_timeout' => (int) env('FIREFLYX_SUGGESTER_TIMEOUT', 1800),
];
