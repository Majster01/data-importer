<?php

/*
 * ConnectionEvent.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace App\FireflyX\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * One step of a bank connection's life, kept for the status page in Pico.
 * Never holds an IBAN, a name, a session id or the authorisation code: only the
 * step, a short reason code and a bounded message (what the bank or Enable
 * Banking said about the failure).
 *
 * @property int         $id
 * @property null|string $connection_name
 * @property null|string $bank
 * @property null|string $flow       create or reauth, when the step belongs to a consent
 * @property string      $step
 * @property string      $level
 * @property null|string $reason
 * @property null|string $message
 * @property Carbon      $created_at
 */
final class ConnectionEvent extends Model
{
    public const string INFO    = 'info';
    public const string WARNING = 'warning';
    public const string ERROR   = 'error';

    private const int MESSAGE_LENGTH = 300;

    public const UPDATED_AT = null;

    protected $table   = 'fireflyx_connection_events';
    protected $guarded = ['id'];

    /**
     * Writes an event and prunes old ones. Recording must never break the flow
     * it describes, so a failure here is only logged.
     */
    public static function record(null|Connection|string $connection, string $step, string $level = self::INFO, ?string $reason = null, ?string $message = null, ?string $flow = null): void
    {
        try {
            self::create([
                'connection_name' => $connection instanceof Connection ? $connection->name : $connection,
                'bank'            => $connection instanceof Connection ? $connection->bank_name : null,
                'flow'            => $flow,
                'step'            => $step,
                'level'           => $level,
                'reason'          => $reason,
                'message'         => null === $message ? null : Str::limit((string) preg_replace('/\s+/', ' ', trim($message)), self::MESSAGE_LENGTH),
                'created_at'      => Carbon::now(),
            ]);
            self::where('created_at', '<', Carbon::now()->subDays((int) config('fireflyx.events_days')))->delete();
        } catch (Throwable $e) {
            Log::warning(sprintf('FireflyX: could not record the %s event: %s', $step, $e->getMessage()));
        }
    }

    public function present(): array
    {
        return [
            'at'         => $this->created_at->toW3cString(),
            'connection' => $this->connection_name,
            'bank'       => $this->bank,
            'flow'       => $this->flow,
            'step'       => $this->step,
            'level'      => $this->level,
            'reason'     => $this->reason,
            'message'    => $this->message,
        ];
    }
}
