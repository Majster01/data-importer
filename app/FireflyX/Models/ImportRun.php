<?php

/*
 * ImportRun.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace App\FireflyX\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What one connection's part of a nightly import did. Not written for a manual
 * import ("Import now"), which has its own row in ManualImport.
 *
 * @property int         $id
 * @property int         $connection_id
 * @property string      $connection_name
 * @property bool        $ok
 * @property int         $created     transactions created
 * @property int         $warnings
 * @property int         $errors
 * @property null|string $first_error
 * @property Carbon      $created_at
 */
final class ImportRun extends Model
{
    public const UPDATED_AT = null;

    protected $table   = 'fireflyx_import_runs';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['ok' => 'boolean'];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }
}
