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
        // Every error message of an import, not only the first: a list of
        // {message, count}, capped by RecordImportResult::errorMessages().
        // Read by the errors page in Pico. Rows from before this column keep
        // first_error / error, and nothing is backfilled.
        foreach (['fireflyx_import_runs', 'fireflyx_manual_imports'] as $table) {
            Schema::table($table, static function (Blueprint $table): void {
                $table->json('error_messages')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (['fireflyx_import_runs', 'fireflyx_manual_imports'] as $table) {
            Schema::table($table, static function (Blueprint $table): void {
                $table->dropColumn('error_messages');
            });
        }
    }
};
