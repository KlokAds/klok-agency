<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Ad accounts connected in Klok Pilot, each linked to the client it is run
 * for. A client can have several (one per page or business).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pilot_accounts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pilot_id')->unique();
            $table->string('name');
            $table->string('external_id', 64)->nullable();
            $table->string('platform', 20)->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('shop')->nullable();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('seen_at')->nullable();
            $table->timestamps();
        });

        foreach (DB::table('clients')->whereNotNull('pilot_ad_account_id')->get(['id', 'pilot_ad_account_id']) as $client) {
            DB::table('pilot_accounts')->insertOrIgnore([
                'pilot_id' => $client->pilot_ad_account_id, 'name' => '#'.$client->pilot_ad_account_id,
                'client_id' => $client->id, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('pilot_ad_account_id');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->unsignedBigInteger('pilot_ad_account_id')->nullable()->after('manager_id');
        });

        Schema::dropIfExists('pilot_accounts');
    }
};
