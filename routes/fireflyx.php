<?php

/*
 * fireflyx.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 *
 * Loaded by FireflyXServiceProvider under /api/fireflyx/v1 with the "api"
 * middleware group. Reached by Pico on the internal network only; Caddy
 * answers 404 for these paths from outside.
 */

declare(strict_types=1);

use App\FireflyX\Http\BankController;
use App\FireflyX\Http\ConnectionController;
use App\FireflyX\Http\LinkController;
use App\FireflyX\Http\ManualImportController;
use App\FireflyX\Http\RequireToken;
use Illuminate\Support\Facades\Route;

Route::middleware(RequireToken::class)->group(static function (): void {
    Route::get('banks', [BankController::class, 'index']);
    Route::get('connections', [ConnectionController::class, 'index']);
    Route::post('connections', [LinkController::class, 'create']);
    Route::get('connections/{name}', [ConnectionController::class, 'show'])->where('name', '[a-z0-9-]+');
    Route::post('connections/{name}/refresh', [ConnectionController::class, 'refresh'])->where('name', '[a-z0-9-]+');
    Route::post('connections/{name}/reauth', [LinkController::class, 'reauth'])->where('name', '[a-z0-9-]+');
    Route::put('connections/{name}/accounts', [LinkController::class, 'accounts'])->where('name', '[a-z0-9-]+');
    Route::post('connections/{name}/imports', [ManualImportController::class, 'store'])->where('name', '[a-z0-9-]+');
    Route::delete('connections/{name}', [ConnectionController::class, 'destroy'])->where('name', '[a-z0-9-]+');
});
