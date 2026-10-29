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
        {file : The exported importer config (JSON), or - to read it from stdin}
        {--name= : Connection name (default: the file name without .json; required with -)}
        {--accept-base : Adopt even if its shared settings differ from the base config}
        {--replace : Replace the session and accounts of an existing connection (after re-linking in the importer UI)}
        {--offline : Do not ask Enable Banking for status and account details}';

    public function handle(SessionRefresher $refresher): int
    {
        $file = (string) $this->argument('file');

        try {
            // stdin lets the host pass a config the container cannot read
            // (mode 600, another uid) without mounting it or running as root.
            $raw    = '-' === $file ? (string) stream_get_contents(STDIN) : (string) @file_get_contents($file);
            $config = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            $base   = BaseConfig::load((string) config('fireflyx.base_config'));
        } catch (ConfigException|JsonException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ('-' === $file && !$this->option('name')) {
            $this->error('--name is required when reading from stdin.');

            return self::FAILURE;
        }
        $name = (string) ($this->option('name') ?: basename($file, '.json'));
        if (1 !== preg_match('/^[a-z0-9-]{1,64}$/', $name)) {
            $this->error(sprintf('"%s" is not a valid connection name (a-z, 0-9, -).', $name));

            return self::FAILURE;
        }
        $existing = Connection::withTrashed()->where('name', $name)->first();
        if (null !== $existing && !$this->option('replace')) {
            $this->error(sprintf('A connection named "%s" already exists (use --replace after re-linking it).', $name));

            return self::FAILURE;
        }
        if ('eb' !== ($config['flow'] ?? null) || [] === ($config['enable_banking_sessions'] ?? []) || [] === ($config['accounts'] ?? [])) {
            $this->error('Not an Enable Banking config with a session and mapped accounts.');

            return self::FAILURE;
        }

        $differences = [];
        foreach ($base->values() as $key => $value) {
            if (self::normalise($config[$key] ?? null) !== self::normalise($value)) {
                $differences[] = $key;
            }
        }
        if ([] !== $differences && !$this->option('accept-base')) {
            $this->error(sprintf('These settings differ from the base config: %s', implode(', ', $differences)));
            $this->line('Align config/importer-base.json first, or pass --accept-base to import this bank with the base settings.');

            return self::FAILURE;
        }

        // Session and account uids change together or not at all: the uids
        // belong to the session (they die with it).
        $connection = DB::transaction(static function () use ($config, $name, $existing): Connection {
            $connection = $existing ?? new Connection(['name' => $name]);
            $connection->fill([
                'lifecycle'    => Connection::LIFECYCLE_READY,
                'bank_name'    => (string) $config['enable_banking_bank'],
                'bank_country' => (string) $config['enable_banking_country'],
                'session_id'   => (string) $config['enable_banking_sessions'][0],
                'auth_id'      => (string) ($config['enable_banking_auth_id'] ?? ''),
                'eb_status'    => null,
                'valid_until'  => null,
                'checked_at'   => null,
                'last_error'   => null,
            ]);
            if (null !== $existing) {
                $connection->relinked_at = Carbon::now();
                $connection->deleted_at  = null;
                $connection->accounts()->delete();
            }
            if (null === $existing && '' !== (string) ($config['created_at'] ?? '')) {
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
            '%s %s: %s %s, %d account(s), status %s.',
            null === $existing ? 'Adopted' : 'Replaced',
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

    /**
     * JSON objects compare equal whatever their key order (a base config that
     * went through `jq -S` has its keys sorted); lists keep their order.
     */
    private static function normalise(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        $value = array_map(self::normalise(...), $value);
        if (!array_is_list($value)) {
            ksort($value);
        }

        return $value;
    }
}
