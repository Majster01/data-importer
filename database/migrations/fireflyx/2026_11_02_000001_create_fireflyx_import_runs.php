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
        // One row per connection per nightly /autoimport: what last_import_* on
        // the connection keeps only for the latest night. Written by
        // RecordImportResult, read by the status page in Pico. History starts
        // the day this table is created; nothing is backfilled.
        Schema::create('fireflyx_import_runs', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('connection_id')->constrained('fireflyx_connections')->cascadeOnDelete();
            // Kept apart from the connection so a removed bank still reads right.
            $table->string('connection_name', 64);
            $table->boolean('ok');
            // "messages" of the import event: one per transaction it created.
            $table->unsignedInteger('created')->default(0);
            $table->unsignedInteger('warnings')->default(0);
            $table->unsignedInteger('errors')->default(0);
            $table->text('first_error')->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fireflyx_import_runs');
    }
};
