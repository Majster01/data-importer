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
        Schema::create('fireflyx_connections', static function (Blueprint $table): void {
            $table->id();
            $table->string('name', 64)->unique();
            // pending → needs_mapping → ready. What Pico shows is derived from
            // this plus the Enable Banking session; see Connection::status().
            $table->string('lifecycle', 20)->default('pending');
            $table->string('bank_name');
            $table->string('bank_country', 2);
            $table->string('bank_logo')->nullable();
            $table->string('psu_type', 20)->default('personal');
            $table->unsignedInteger('max_consent_days')->nullable();
            // Encrypted with APP_KEY (Connection::$casts), so text, not string.
            $table->text('session_id')->nullable();
            $table->text('auth_id')->nullable();
            $table->string('eb_status', 32)->nullable();
            $table->timestamp('valid_until')->nullable();
            $table->timestamp('checked_at')->nullable();
            // First-run history; ignored once an import has succeeded.
            $table->date('import_since')->nullable();
            $table->timestamp('last_import_at')->nullable();
            $table->boolean('last_import_ok')->nullable();
            $table->json('last_import_summary')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamp('relinked_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('fireflyx_connection_accounts', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('connection_id')->constrained('fireflyx_connections')->cascadeOnDelete();
            // Stable across sessions; uid is not (it dies with the session).
            $table->string('identification_hash')->nullable();
            $table->string('uid');
            $table->string('iban')->nullable();
            $table->string('name')->nullable();
            $table->string('currency', 3)->nullable();
            // null = do not import this account; 0 = let the importer create one.
            $table->unsignedBigInteger('firefly_account_id')->nullable();
            $table->timestamps();
            $table->unique(['connection_id', 'uid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fireflyx_connection_accounts');
        Schema::dropIfExists('fireflyx_connections');
    }
};
