<?php

/*
 * ConnectionAccount.php
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

namespace App\FireflyX\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A bank account inside a connection and the Firefly III account it feeds.
 *
 * @property int         $id
 * @property int         $connection_id
 * @property null|string $identification_hash
 * @property string      $uid
 * @property null|string $iban
 * @property null|string $name
 * @property null|string $currency
 * @property null|int    $firefly_account_id
 */
final class ConnectionAccount extends Model
{
    protected $table   = 'fireflyx_connection_accounts';
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['firefly_account_id' => 'integer'];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(Connection::class);
    }

    public function maskedIban(): ?string
    {
        if (null === $this->iban || strlen($this->iban) < 8) {
            return $this->iban;
        }

        return sprintf('%s…%s', substr($this->iban, 0, 4), substr($this->iban, -4));
    }
}
