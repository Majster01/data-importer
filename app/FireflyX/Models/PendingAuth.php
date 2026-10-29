<?php

/*
 * PendingAuth.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace App\FireflyX\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A consent flow waiting for the bank to send the user back.
 *
 * @property string      $state
 * @property string      $kind
 * @property null|int    $connection_id
 * @property null|array  $payload
 * @property string      $return_path
 * @property Carbon      $expires_at
 * @property null|Carbon $used_at
 */
final class PendingAuth extends Model
{
    public const string KIND_REAUTH = 'reauth';

    // "fx-" lets Caddy send these callbacks here instead of to upstream's,
    // whose state is always a UUID.
    public const string STATE_PATTERN = '/^fx-[0-9a-f]{32}$/';

    public $incrementing   = false;
    protected $table       = 'fireflyx_pending_auths';
    protected $primaryKey  = 'state';
    protected $keyType     = 'string';
    protected $guarded     = [];

    protected function casts(): array
    {
        return ['payload' => 'array', 'expires_at' => 'datetime', 'used_at' => 'datetime'];
    }

    public static function newState(): string
    {
        return 'fx-'.bin2hex(random_bytes(16));
    }

    /**
     * Marks the state used and returns it, or null when it is unknown, already
     * used or expired. The conditional update makes a replayed callback lose.
     */
    public static function consume(string $state): ?self
    {
        if (1 !== preg_match(self::STATE_PATTERN, $state)) {
            return null;
        }
        $claimed = self::whereKey($state)
            ->whereNull('used_at')
            ->where('expires_at', '>', Carbon::now())
            ->update(['used_at' => Carbon::now()]);

        return 1 === $claimed ? self::find($state) : null;
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }
}
