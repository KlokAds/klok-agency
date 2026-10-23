<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How each person wants each group of notifications: on the phone, by
 * email, or only in the bell — {"money": {"push": true, "email": false}, …}.
 * The work goes to whoever has the permission for it; an Owner only hears
 * about a group they switched on, or that nobody else covers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->json('notify_prefs')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('notify_prefs');
        });
    }
};
