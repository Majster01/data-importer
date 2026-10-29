<?php

/*
 * FireflyXServiceProvider.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace App\FireflyX;

use App\Events\ImportedTransactions;
use App\FireflyX\Console\AdoptConfig;
use App\FireflyX\Console\RefreshConnections;
use App\FireflyX\Console\RenderImports;
use App\FireflyX\Console\ReportImports;
use App\FireflyX\Listeners\RecordImportResult;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Everything the fork adds is registered here, so upstream files stay untouched
 * apart from the one line in config/app.php that loads this provider.
 */
final class FireflyXServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Route::middleware('api')
            ->prefix('api/fireflyx/v1')
            ->group(base_path('routes/fireflyx.php'));

        $this->loadMigrationsFrom(database_path('migrations/fireflyx'));

        // Upstream fires this once per config file after every autoimport.
        Event::listen(ImportedTransactions::class, RecordImportResult::class);

        if ($this->app->runningInConsole()) {
            $this->commands([
                AdoptConfig::class,
                RefreshConnections::class,
                RenderImports::class,
                ReportImports::class,
            ]);
        }
    }
}
