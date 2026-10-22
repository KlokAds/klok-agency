<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An invoice line can bill one campaign's spend over a period; it keeps the
 * period's results and clicks so the invoice shows what the money bought.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->foreignId('campaign_id')->nullable()->after('kind')->constrained()->nullOnDelete();
            $table->date('period_from')->nullable()->after('campaign_id');
            $table->date('period_to')->nullable()->after('period_from');
            $table->unsignedInteger('results')->nullable()->after('rate');
            $table->unsignedInteger('clicks')->nullable()->after('results');
        });
    }

    public function down(): void
    {
        Schema::table('invoice_items', function (Blueprint $table) {
            $table->dropConstrainedForeignId('campaign_id');
            $table->dropColumn(['period_from', 'period_to', 'results', 'clicks']);
        });
    }
};
