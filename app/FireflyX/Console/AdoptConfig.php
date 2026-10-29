<?php

/*
 * AdoptConfig.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace App\FireflyX\Console;

use App\FireflyX\EnableBanking\SessionRefresher;
use App\FireflyX\Import\BaseConfig;
use App\FireflyX\Import\ConfigException;
use App\FireflyX\Models\Connection;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use JsonException;

/**
 * One-off migration: turn a hand-exported importer config into a connection.
 * Only the per-connection keys are kept; everything else must already match
 * the shared base config, or the import would silently change behaviour.
 */
final class AdoptConfig extends Command
{
    protected $description = 'Adopt an exported Enable Banking import config as a bank connection.';
    protected $signature   = 'fireflyx:connections:adopt
        {file : The exported importer config (JSON)}
        {--name= : Connection name (default: the file name without .json)}
        {--accept-base : Adopt even if its shared settings differ from the base config}
        {--offline : Do not ask Enable Banking for status and account details}';

    public function handle(SessionRefresher $refresher): int
    {
        $file = (string) $this->argument('file');

        try {
            $config = json_decode((string) @file_get_contents($file), true, 512, JSON_THROW_ON_ERROR);
            $base   = BaseConfig::load((string) config('fireflyx.base_config'));
        } catch (ConfigException|JsonException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $name = (string) ($this->option('name') ?: basename($file, '.json'));
        if (1 !== preg_match('/^[a-z0-9-]{1,64}$/', $name)) {
            $this->error(sprintf('"%s" is not a valid connection name (a-z, 0-9, -).', $name));

            return self::FAILURE;
        }
        if (Connection::withTrashed()->where('name', $name)->exists()) {
            $this->error(sprintf('A connection named "%s" already exists.', $name));

            return self::FAILURE;
        }
        if ('eb' !== ($config['flow'] ?? null) || [] === ($config['enable_banking_sessions'] ?? []) || [] === ($config['accounts'] ?? [])) {
            $this->error('Not an Enable Banking config with a session and mapped accounts.');

            return self::FAILURE;
        }

        $differences = [];
        foreach ($base->values() as $key => $value) {
            if (($config[$key] ?? null) !== $value) {
                $differences[] = $key;
            }
        }
        if ([] !== $differences && !$this->option('accept-base')) {
            $this->error(sprintf('These settings differ from the base config: %s', implode(', ', $differences)));
            $this->line('Align config/importer-base.json first, or pass --accept-base to import this bank with the base settings.');

            return self::FAILURE;
        }

        $connection = DB::transaction(static function () use ($config, $name): Connection {
            $connection = new Connection([
                'name'         => $name,
                'lifecycle'    => Connection::LIFECYCLE_READY,
                'bank_name'    => (string) $config['enable_banking_bank'],
                'bank_country' => (string) $config['enable_banking_country'],
                'session_id'   => (string) $config['enable_banking_sessions'][0],
                'auth_id'      => (string) ($config['enable_banking_auth_id'] ?? ''),
            ]);
            if ('' !== (string) ($config['created_at'] ?? '')) {
                $connection->created_at = Carbon::parse($config['created_at']);
            }
            $connection->save();
            foreach ($config['accounts'] as $uid => $fireflyAccountId) {
                $connection->accounts()->create(['uid' => (string) $uid, 'firefly_account_id' => (int) $fireflyAccountId]);
            }

            return $connection;
        });

        if (!$this->option('offline')) {
            $connection = $refresher->refresh($connection->load('accounts'));
        }
        $this->line(sprintf(
            'Adopted %s: %s %s, %d account(s), status %s.',
            $connection->name,
            $connection->bank_name,
            $connection->bank_country,
            $connection->accounts()->count(),
            $connection->status()
        ));
        if (count($config['enable_banking_sessions']) > 1) {
            $this->warn('The config listed more than one session; only the first was kept.');
        }

        return self::SUCCESS;
    }
}
