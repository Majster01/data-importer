<?php

/*
 * ManualImport.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace App\FireflyX\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * An import of one connection over a time frame chosen in Pico, followed by
 * the category suggester over the same time frame.
 *
 * @property int         $id
 * @property int         $connection_id
 * @property Carbon      $date_from
 * @property Carbon      $date_to
 * @property string      $status
 * @property null|array  $summary
 * @property null|string $error
 * @property null|array  $suggestions
 * @property null|string $suggest_error
 * @property null|Carbon $finished_at
 * @property Carbon      $created_at
 */
final class ManualImport extends Model
{
    public const string QUEUED     = 'queued';
    public const string IMPORTING  = 'importing';
    public const string SUGGESTING = 'suggesting';
    public const string DONE       = 'done';
    public const string FAILED     = 'failed';

    public const array ACTIVE = [self::QUEUED, self::IMPORTING, self::SUGGESTING];

    // A run that has not finished after this long died with its process.
    public const int STALE_AFTER_MINUTES = 90;

    protected $table   = 'fireflyx_manual_imports';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'date_from'   => 'date',
            'date_to'     => 'date',
            'summary'     => 'array',
            'suggestions' => 'array',
            'finished_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    /**
     * Imports still in progress. One at a time, for every connection together:
     * the bank limits unattended requests per day anyway, and the suggester
     * must not run twice at once.
     */
    public static function active(): \Illuminate\Database\Eloquent\Builder
    {
        return self::whereIn('status', self::ACTIVE)
            ->where('updated_at', '>', Carbon::now()->subMinutes(self::STALE_AFTER_MINUTES));
    }

    public function isActive(): bool
    {
        return in_array($this->status, self::ACTIVE, true);
    }

    /**
     * What Pico reads. A run whose process died without finishing is shown as
     * failed rather than running forever.
     */
    public function present(): array
    {
        $stale  = $this->isActive() && $this->updated_at->lessThanOrEqualTo(Carbon::now()->subMinutes(self::STALE_AFTER_MINUTES));
        $status = $stale ? self::FAILED : $this->status;

        return [
            'id'            => $this->id,
            'status'        => $status,
            'date_from'     => $this->date_from->format('Y-m-d'),
            'date_to'       => $this->date_to->format('Y-m-d'),
            'started_at'    => $this->created_at->toW3cString(),
            'finished_at'   => $this->finished_at?->toW3cString(),
            'summary'       => $this->summary,
            'error'         => $stale ? 'The import was interrupted.' : $this->error,
            'suggestions'   => $this->suggestions,
            'suggest_error' => $this->suggest_error,
        ];
    }
}
