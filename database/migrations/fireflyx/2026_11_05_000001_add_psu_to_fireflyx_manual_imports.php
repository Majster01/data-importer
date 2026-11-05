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
        // Who asked, as the bank should see it: the address and browser of the
        // person who tapped "Import" in Pico. Sent to Enable Banking as PSU-*
        // headers so the bank treats the run as attended, not as background
        // access with its small daily quota. Both stay empty when unknown.
        Schema::table('fireflyx_manual_imports', static function (Blueprint $table): void {
            $table->string('psu_ip_address', 45)->nullable()->after('date_to');
            $table->string('psu_user_agent', 512)->nullable()->after('psu_ip_address');
        });
    }

    public function down(): void
    {
        Schema::table('fireflyx_manual_imports', static function (Blueprint $table): void {
            $table->dropColumn(['psu_ip_address', 'psu_user_agent']);
        });
    }
};
