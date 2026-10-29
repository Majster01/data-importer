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
        // One row per "Import now" started from Pico: the chosen time frame and
        // how far the run got (import, then the category suggester).
        Schema::create('fireflyx_manual_imports', static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('connection_id')->constrained('fireflyx_connections')->cascadeOnDelete();
            $table->date('date_from');
            $table->date('date_to');
            $table->string('status', 12);
            $table->json('summary')->nullable();
            $table->text('error')->nullable();
            $table->json('suggestions')->nullable();
            $table->text('suggest_error')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fireflyx_manual_imports');
    }
};
