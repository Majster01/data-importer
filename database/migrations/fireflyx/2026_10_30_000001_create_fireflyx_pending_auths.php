<?php

/*
 * FireflyX fork of the Firefly III Data Importer. AGPL-3.0, see LICENSE.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // One row per consent flow started from Pico. The state travels to the
        // bank and back; it is single use and short-lived, and it is the only
        // thing that authorises the public callback.
        Schema::create('fireflyx_pending_auths', static function (Blueprint $table): void {
            $table->string('state', 40)->primary();
            $table->string('kind', 10);
            $table->foreignId('connection_id')->nullable()->constrained('fireflyx_connections')->cascadeOnDelete();
            $table->json('payload')->nullable();
            $table->string('return_path');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fireflyx_pending_auths');
    }
};
