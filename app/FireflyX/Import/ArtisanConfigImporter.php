<?php

/*
 * ArtisanConfigImporter.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace App\FireflyX\Import;

use App\Events\ImportedTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;

final class ArtisanConfigImporter implements ConfigImporter
{
    public function import(string $configFile): array
    {
        $result = null;
        // Upstream fires this once per config file, however the import ended.
        Event::listen(ImportedTransactions::class, static function (ImportedTransactions $event) use (&$result): void {
            $result = $event;
        });
        $exitCode = Artisan::call('importer:import', ['config' => $configFile]);

        return ['exit_code' => $exitCode, 'result' => $result];
    }
}
