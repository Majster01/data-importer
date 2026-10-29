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
];
