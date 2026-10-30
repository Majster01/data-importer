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
        // What happened to a bank connection, step by step: consent started,
        // bank declined, session failed, accounts mapped, consent ended, and so
        // on. Read by the status page in Pico.
        //
        // Deliberately no foreign key: a consent that fails while linking a NEW
        // bank deletes its connection row, and that is exactly the failure worth
        // keeping a record of. The name and bank are copied instead.
        Schema::create('fireflyx_connection_events', static function (Blueprint $table): void {
            $table->id();
            $table->string('connection_name', 64)->nullable()->index();
            $table->string('bank', 128)->nullable();
            $table->string('flow', 12)->nullable();
            $table->string('step', 32);
            $table->string('level', 8);
            $table->string('reason', 32)->nullable();
            $table->text('message')->nullable();
            $table->timestamp('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('fireflyx_connection_events');
    }
};
