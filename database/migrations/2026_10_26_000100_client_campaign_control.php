<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A client pauses their own campaign from the portal (and only they, or the team, switch it back
 * on); an ad account Meta could not charge or disabled stops the ads on the agency's side, which
 * the client is told about.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->timestamp('paused_by_client_at')->nullable()->after('paused_for_credit_at');
        });
        Schema::table('pilot_accounts', function (Blueprint $table) {
            $table->string('problem', 20)->nullable()->after('seen_at');
            $table->timestamp('problem_since')->nullable()->after('problem');
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', fn (Blueprint $table) => $table->dropColumn('paused_by_client_at'));
        Schema::table('pilot_accounts', fn (Blueprint $table) => $table->dropColumn(['problem', 'problem_since']));
    }
};
