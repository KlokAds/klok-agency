<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The agency side: each ad a client pays for (an "ad run"), the work and
 * reminders around it, and in-app notifications.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->foreignId('manager_id')->nullable()->after('status')->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('pilot_shop_id')->nullable()->after('manager_id');
        });

        Schema::create('ad_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('client_id')->constrained();
            $table->foreignId('account_id')->constrained();
            $table->string('title');
            $table->bigInteger('budget_usd');
            $table->bigInteger('daily_usd')->nullable();
            $table->unsignedSmallInteger('days');
            $table->date('start_on');
            $table->date('end_on')->index();
            $table->unsignedTinyInteger('remind_days_before')->default(1);
            $table->foreignId('usd_entry_id')->nullable()->constrained('usd_entries')->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('bdt_entries')->nullOnDelete();
            $table->foreignId('renewed_from_id')->nullable()->constrained('ad_runs')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status', 20)->default('running')->index();
            $table->string('result', 10)->nullable();
            $table->unsignedInteger('results')->nullable();
            $table->bigInteger('cost_per_result')->nullable();
            $table->text('result_note')->nullable();
            $table->timestamp('result_at')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tasks', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('notes')->nullable();
            $table->string('kind', 20)->default('task');
            $table->string('priority', 10)->default('normal');
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('ad_run_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('due_at')->nullable()->index();
            $table->dateTime('remind_at')->nullable()->index();
            $table->string('repeat', 10)->default('none');
            $table->timestamp('notified_at')->nullable();
            $table->timestamp('done_at')->nullable()->index();
            $table->foreignId('done_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('notifications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notifications');
        Schema::dropIfExists('tasks');
        Schema::dropIfExists('ad_runs');

        Schema::table('clients', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manager_id');
            $table->dropColumn('pilot_shop_id');
        });
    }
};
