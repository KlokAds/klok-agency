<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Invoices have four statuses now: sent, partial, paid and void (Cancel).
 * Drafts become sent; "overdue" is no longer a status (past the due date
 * is shown beside it), so those go back to sent or partial.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('invoices')->where('status', 'draft')->update(['status' => 'sent']);
        DB::table('invoices')->where('status', 'overdue')->where('paid', '>', 0)->update(['status' => 'partial']);
        DB::table('invoices')->where('status', 'overdue')->update(['status' => 'sent']);
    }

    public function down(): void
    {
        // Nothing to undo: the old statuses carried no more than these.
    }
};
