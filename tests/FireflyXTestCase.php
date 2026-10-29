<?php

/*
 * FireflyXTestCase.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * In-memory SQLite and a fixed APP_KEY (for the encrypted session columns).
 */
abstract class FireflyXTestCase extends TestCase
{
    use RefreshDatabase;

    protected string $importDir;

    /**
     * Configured on the booted app rather than through the environment, so it
     * cannot be shadowed by whatever the container or phpunit.xml sets.
     */
    public function createApplication()
    {
        $app = parent::createApplication();
        $app['config']->set([
            'database.default'                     => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',
            'app.key'                              => 'base64:'.base64_encode(str_repeat('k', 32)),
            // Matches the server; created_at is rendered with its offset.
            'app.timezone'                         => 'Europe/Ljubljana',
            'fireflyx.api_token'                   => str_repeat('t', 40),
            'fireflyx.base_config'                 => __DIR__.'/fixtures/fireflyx/base.json',
        ]);
        date_default_timezone_set('Europe/Ljubljana');

        return $app;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->importDir = sys_get_temp_dir().'/fireflyx-import-'.bin2hex(random_bytes(4));
        mkdir($this->importDir);
        config(['fireflyx.import_dir' => $this->importDir]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->importDir.'/{,.}*', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        @rmdir($this->importDir);
        parent::tearDown();
    }

    protected function fixture(string $name): array
    {
        return json_decode((string) file_get_contents(__DIR__.'/fixtures/fireflyx/'.$name), true, 512, JSON_THROW_ON_ERROR);
    }
}
