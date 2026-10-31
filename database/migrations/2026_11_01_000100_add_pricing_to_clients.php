<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How a client's dollar is priced, set on the client: the FX batch plus a commission (theirs, or
 * the agency's when left empty), or a fixed taka rate whatever the batch cost (GFFS: ৳150).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('rate_mode', 12)->default('commission')->after('renew_days');
            $table->decimal('fixed_rate', 8, 2)->nullable()->after('rate_mode');
            $table->decimal('commission_percent', 5, 2)->nullable()->after('fixed_rate');
        });
    }

    public function down(): void
    {
        Schema::table('clients', fn (Blueprint $table) => $table->dropColumn(['rate_mode', 'fixed_rate', 'commission_percent']));
    }
};
