<?php

/*
 * ConfigImporter.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace App\FireflyX\Import;

use App\Events\ImportedTransactions;

/**
 * Imports one rendered config file with the importer's own `importer:import`.
 * An interface so tests can stand in for the bank and Firefly III.
 */
interface ConfigImporter
{
    /**
     * @return array{exit_code: int, result: null|ImportedTransactions} the result is null when the import never got as far as reporting
     */
    public function import(string $configFile): array;
}
