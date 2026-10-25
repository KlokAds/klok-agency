<?php

use App\Models\Campaign;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** A campaign's page is reached by its name: /campaigns/summer-sale, /portal/campaigns/summer-sale. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('campaigns', function (Blueprint $table) {
            $table->string('slug', 120)->nullable()->unique()->after('name');
        });

        Campaign::query()->orderBy('id')->each(function (Campaign $campaign) {
            $campaign->forceFill(['slug' => Campaign::slugFor($campaign)])->saveQuietly();
        });
    }

    public function down(): void
    {
        Schema::table('campaigns', fn (Blueprint $table) => $table->dropColumn('slug'));
    }
};
