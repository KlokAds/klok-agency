<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** Days a renewal invoice is made for by itself two days before a campaign ends; null asks the client. */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->unsignedSmallInteger('renew_days')->nullable()->after('manager_id');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('renew_days');
        });
    }
};
