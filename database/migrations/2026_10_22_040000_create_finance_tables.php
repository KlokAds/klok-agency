<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Money is stored as integer minor units: USD in cents, BDT in poisha.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('password');
            $table->boolean('must_change_password')->default(false)->after('is_active');
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
        });

        Schema::create('clients', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->string('type', 20)->default('client');
            $table->string('status', 20)->default('active');
            $table->string('phone', 40)->nullable();
            $table->string('email')->nullable();
            $table->string('company')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->string('kind', 20);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('fx_batches', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('number')->unique();
            $table->date('purchased_on')->nullable();
            $table->string('description');
            $table->bigInteger('usd');
            $table->bigInteger('bdt');
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('expense_heads', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('designation')->nullable();
            $table->string('phone', 40)->nullable();
            $table->bigInteger('monthly_salary')->default(0);
            $table->date('joined_on')->nullable();
            $table->date('left_on')->nullable();
            $table->string('status', 20)->default('active');
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('bdt_entries', function (Blueprint $table) {
            $table->id();
            $table->date('entry_date')->nullable()->index();
            $table->unsignedBigInteger('sequence')->index();
            $table->string('description');
            $table->string('category', 30)->index();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('expense_head_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('salary_month', 7)->nullable();
            $table->string('salary_kind', 10)->nullable();
            $table->foreignId('fx_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('amount');
            $table->text('notes')->nullable();
            $table->string('review_note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('usd_entries', function (Blueprint $table) {
            $table->id();
            $table->date('entry_date')->nullable()->index();
            $table->unsignedBigInteger('sequence')->index();
            $table->string('description');
            $table->string('category', 30)->index();
            $table->foreignId('account_id')->constrained();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->bigInteger('amount');
            $table->string('status', 20)->default('na');
            $table->foreignId('fx_batch_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('settled_by_id')->nullable()->constrained('bdt_entries')->nullOnDelete();
            $table->uuid('pair_key')->nullable()->index();
            $table->text('notes')->nullable();
            $table->string('review_note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('reconciliations', function (Blueprint $table) {
            $table->id();
            $table->date('checked_on');
            $table->bigInteger('app_balance');
            $table->bigInteger('system_balance');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('reconciliations');
        Schema::dropIfExists('usd_entries');
        Schema::dropIfExists('bdt_entries');
        Schema::dropIfExists('employees');
        Schema::dropIfExists('expense_heads');
        Schema::dropIfExists('fx_batches');
        Schema::dropIfExists('accounts');
        Schema::dropIfExists('clients');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_active', 'must_change_password', 'last_login_at', 'last_login_ip']);
        });
    }
};
