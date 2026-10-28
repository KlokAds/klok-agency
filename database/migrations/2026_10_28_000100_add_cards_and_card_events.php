<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * More than one card (NSave main and its copy), each with its own balance:
 * - accounts.last4: the card's last four digits, how a notification or a Meta charge finds it;
 * - usd_entries.card_id: the card that paid a platform charge (empty = the main card);
 * - card_events: every NSave app notification the phone forwards, read and booked.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('accounts', fn (Blueprint $table) => $table->string('last4', 4)->nullable()->after('kind'));
        Schema::table('usd_entries', fn (Blueprint $table) => $table->foreignId('card_id')->nullable()->after('account_id')->constrained('accounts')->nullOnDelete());

        Schema::create('card_events', function (Blueprint $table) {
            $table->id();
            $table->string('source', 20)->default('nsave');
            $table->string('package', 120)->nullable();
            $table->text('body');
            $table->string('hash', 64)->unique();
            $table->timestamp('posted_at')->nullable();
            // charge, refund, topup, declined, other
            $table->string('kind', 20)->default('other');
            $table->unsignedBigInteger('amount')->nullable();
            $table->string('currency', 3)->nullable();
            $table->string('merchant', 120)->nullable();
            $table->string('last4', 4)->nullable();
            // booked, duplicate, held, ignored
            $table->string('status', 20)->default('held');
            $table->string('problem', 255)->nullable();
            $table->foreignId('card_id')->nullable()->constrained('accounts')->nullOnDelete();
            $table->foreignId('usd_entry_id')->nullable()->constrained('usd_entries')->nullOnDelete();
            $table->timestamps();
            $table->index(['status', 'posted_at']);
        });

        // The main card's digits, as the Meta charges have shown them (Settings › Meta card).
        $main = DB::table('accounts')->where('kind', 'card')->orderBy('sort')->first();
        $digits = array_values(array_filter(array_map('trim', explode(',', (string) DB::table('settings')->where('key', 'meta_cards')->value('value')))));
        $digits = $digits ?: DB::table('meta_receipts')->where('status', 'booked')->whereNotNull('card_last4')->distinct()->pluck('card_last4')->all();

        if ($main !== null && count($digits) === 1) {
            DB::table('accounts')->where('id', $main->id)->update(['last4' => $digits[0]]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('card_events');
        Schema::table('usd_entries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('card_id');
        });
        Schema::table('accounts', fn (Blueprint $table) => $table->dropColumn('last4'));
    }
};
