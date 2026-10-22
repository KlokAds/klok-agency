<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A client's daily ad numbers come from its Klok Pilot ad account, not a
 * whole Pilot shop (one shop can hold several clients' accounts).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->renameColumn('pilot_shop_id', 'pilot_ad_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->renameColumn('pilot_ad_account_id', 'pilot_shop_id');
        });
    }
};
