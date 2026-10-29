<?php

/*
 * Connection.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace App\FireflyX\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * One Enable Banking consent at one bank, with its accounts.
 *
 * @property int                  $id
 * @property string               $name
 * @property string               $lifecycle
 * @property string               $bank_name
 * @property string               $bank_country
 * @property null|string          $bank_logo
 * @property string               $psu_type
 * @property null|int             $max_consent_days
 * @property null|string          $session_id
 * @property null|string          $auth_id
 * @property null|string          $eb_status
 * @property null|Carbon          $valid_until
 * @property null|Carbon          $checked_at
 * @property null|Carbon          $import_since
 * @property null|Carbon          $last_import_at
 * @property null|bool            $last_import_ok
 * @property null|array           $last_import_summary
 * @property null|string          $last_error
 * @property null|Carbon          $relinked_at
 * @property Carbon               $created_at
 */
final class Connection extends Model
{
    use SoftDeletes;

    public const string LIFECYCLE_PENDING       = 'pending';
    public const string LIFECYCLE_NEEDS_MAPPING = 'needs_mapping';
    public const string LIFECYCLE_READY         = 'ready';

    // Enable Banking session statuses after which the consent is gone for good.
    private const array DEAD_EB_STATUSES = ['EXPIRED', 'REVOKED', 'CLOSED', 'CANCELLED', 'INVALID'];

    protected $table   = 'fireflyx_connections';
    protected $guarded = ['id'];
    protected $hidden  = ['session_id', 'auth_id'];

    protected function casts(): array
    {
        return [
            'session_id'          => 'encrypted',
            'auth_id'             => 'encrypted',
            'max_consent_days'    => 'integer',
            'valid_until'         => 'datetime',
            'checked_at'          => 'datetime',
            'import_since'        => 'date',
            'last_import_at'      => 'datetime',
            'last_import_ok'      => 'boolean',
            'last_import_summary' => 'array',
            'relinked_at'         => 'datetime',
        ];
    }

    public function accounts(): HasMany
    {
        return $this->hasMany(ConnectionAccount::class);
    }

    /**
     * What Pico shows: pending, needs_mapping, expired, error, expiring or active.
     */
    public function status(?Carbon $now = null): string
    {
        $now ??= Carbon::now();
        if (self::LIFECYCLE_READY !== $this->lifecycle) {
            return $this->lifecycle;
        }
        if (in_array($this->eb_status, self::DEAD_EB_STATUSES, true)
            || ($this->valid_until instanceof Carbon && $this->valid_until->lessThanOrEqualTo($now))) {
            return 'expired';
        }
        if (null !== $this->last_error) {
            return 'error';
        }
        if (null !== ($days = $this->daysLeft($now)) && $days <= (int) config('fireflyx.warn_days')) {
            return 'expiring';
        }

        return 'active';
    }

    public function daysLeft(?Carbon $now = null): ?int
    {
        if (!$this->valid_until instanceof Carbon) {
            return null;
        }

        return max(0, (int) floor(($now ?? Carbon::now())->diffInDays($this->valid_until, false)));
    }

    /**
     * Rendered for the nightly import. An "error" connection is still tried:
     * the import then logs the real reason, which the nightly check reports.
     */
    public function isImportable(?Carbon $now = null): bool
    {
        return in_array($this->status($now), ['active', 'expiring', 'error'], true)
            && null !== $this->session_id
            && $this->mappedAccounts()->isNotEmpty();
    }

    /**
     * @return \Illuminate\Support\Collection<int, ConnectionAccount>
     */
    public function mappedAccounts(): \Illuminate\Support\Collection
    {
        return $this->accounts->filter(static fn (ConnectionAccount $account): bool => null !== $account->firefly_account_id)->values();
    }

    public function configFileName(): string
    {
        return sprintf('%s.json', $this->name);
    }
}
