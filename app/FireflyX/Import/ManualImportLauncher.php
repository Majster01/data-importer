<?php

/*
 * ManualImportLauncher.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace App\FireflyX\Import;

use App\FireflyX\Models\ManualImport;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Starts `fireflyx:manual-import` detached from the web request: an import
 * takes minutes, and the web worker (and Pico's request) must not wait for it.
 */
class ManualImportLauncher
{
    public function launch(ManualImport $run): void
    {
        // Under php-fpm PHP_BINARY is the fpm binary, which cannot run artisan.
        $php = (new PhpExecutableFinder())->find(false) ?: 'php';
        Process::fromShellCommandline(sprintf(
            'nohup %s %s fireflyx:manual-import %d >/dev/null 2>&1 &',
            escapeshellarg($php),
            escapeshellarg(base_path('artisan')),
            $run->id
        ), base_path())->run();
    }
}
