<?php

/*
 * BaseConfig.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace App\FireflyX\Import;

use App\Services\Shared\Configuration\Configuration;
use JsonException;
use Throwable;

/**
 * The import config every connection shares. A connection contributes only
 * CONNECTION_KEYS and the nightly render only WINDOW_KEYS; everything else
 * (duplicate detection, rules, webhooks, …) comes from here.
 */
final class BaseConfig
{
    public const array CONNECTION_KEYS = [
        'accounts',
        'created_at',
        'enable_banking_auth_id',
        'enable_banking_bank',
        'enable_banking_country',
        'enable_banking_sessions',
    ];

    public const array WINDOW_KEYS = [
        'date_not_after',
        'date_not_before',
        'date_range',
        'date_range_number',
        'date_range_unit',
    ];

    private function __construct(private readonly array $values) {}

    public static function load(string $path): self
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new ConfigException(sprintf('Base import config "%s" is missing or unreadable.', $path));
        }

        try {
            $values = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new ConfigException(sprintf('Base import config "%s" is not valid JSON: %s', $path, $e->getMessage()));
        }
        if (!is_array($values) || array_is_list($values)) {
            throw new ConfigException(sprintf('Base import config "%s" must be a JSON object.', $path));
        }

        return self::fromArray($values);
    }

    public static function fromArray(array $values): self
    {
        // A stray "accounts" here would be deep-merged into every connection.
        $forbidden = array_values(array_intersect(array_keys($values), [...self::CONNECTION_KEYS, ...self::WINDOW_KEYS]));
        if ([] !== $forbidden) {
            throw new ConfigException(sprintf('Base import config must not set per-connection or date keys: %s', implode(', ', $forbidden)));
        }
        if ('eb' !== ($values['flow'] ?? null)) {
            throw new ConfigException('Base import config must have "flow": "eb".');
        }
        // "classic" hashes the whole payload, and banks revise entries after
        // booking, so the overlapping window re-imports them. external_id is
        // the bank's own stable reference.
        if ('cell' !== ($values['duplicate_detection_method'] ?? null) || 'external-id' !== ($values['unique_column_type'] ?? null)) {
            throw new ConfigException('Base import config must use "duplicate_detection_method": "cell" with "unique_column_type": "external-id".');
        }

        try {
            Configuration::fromArray($values);
        } catch (Throwable $e) {
            throw new ConfigException(sprintf('The importer rejects the base import config: %s', $e->getMessage()));
        }

        return new self($values);
    }

    public function values(): array
    {
        return $this->values;
    }
}
