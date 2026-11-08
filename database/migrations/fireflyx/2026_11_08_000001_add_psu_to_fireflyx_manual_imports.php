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
        // Who tapped "Import now": sent to the bank as PSU-IP-Address and
        // PSU-User-Agent so the run counts as user-initiated. Only kept while
        // the run is going, then set back to null.
        Schema::table('fireflyx_manual_imports', static function (Blueprint $table): void {
            $table->string('psu_ip', 45)->nullable();
            $table->string('psu_user_agent', 256)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('fireflyx_manual_imports', static function (Blueprint $table): void {
            $table->dropColumn(['psu_ip', 'psu_user_agent']);
        });
    }
};
