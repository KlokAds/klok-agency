<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Billing by campaign: campaigns come from Klok Pilot, each is linked to the
 * client it runs for, and its daily spend becomes that client's ad spend in
 * the USD book. A client's advance payment carries the USD it buys.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('pilot_campaign_id')->unique();
            $table->foreignId('pilot_account_id')->constrained()->cascadeOnDelete();
            $table->string('external_id', 64)->nullable();
            $table->string('name');
            $table->string('status', 30)->nullable();
            $table->string('objective', 60)->nullable();
            $table->bigInteger('daily_budget')->nullable();
            $table->timestamp('start_at')->nullable();
            $table->timestamp('stop_at')->nullable();
            $table->bigInteger('spend_total')->default(0);
            $table->bigInteger('spend_7d')->default(0);
            $table->date('last_spend_day')->nullable();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->date('bill_from')->nullable();
            $table->timestamps();
        });

        Schema::create('campaign_days', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->date('day');
            $table->bigInteger('spend');
            $table->unsignedInteger('results')->default(0);
            $table->unsignedInteger('clicks')->default(0);
            $table->unique(['campaign_id', 'day']);
        });

        Schema::table('usd_entries', function (Blueprint $table) {
            $table->string('source', 20)->nullable()->after('pair_key');
            $table->string('source_key', 80)->nullable()->unique()->after('source');
        });

        Schema::table('bdt_entries', function (Blueprint $table) {
            $table->bigInteger('usd_credit')->nullable()->after('amount');
        });
    }

    public function down(): void
    {
        Schema::table('bdt_entries', function (Blueprint $table) {
            $table->dropColumn('usd_credit');
        });

        Schema::table('usd_entries', function (Blueprint $table) {
            $table->dropUnique(['source_key']);
            $table->dropColumn(['source', 'source_key']);
        });

        Schema::dropIfExists('campaign_days');
        Schema::dropIfExists('campaigns');
    }
};
