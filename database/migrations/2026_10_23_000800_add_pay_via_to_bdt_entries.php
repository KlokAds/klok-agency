<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * How a client's money came in. Only MFS (bKash, Nagad, Rocket…) costs a
 * fee — 1% now — bank transfers and cash do not.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bdt_entries', function (Blueprint $table) {
            $table->string('pay_via', 10)->nullable()->after('client_id');
        });

        DB::table('settings')->where('key', 'payment_fee_percent')->update(['value' => '1']);
    }

    public function down(): void
    {
        Schema::table('bdt_entries', function (Blueprint $table) {
            $table->dropColumn('pay_via');
        });
    }
};
