<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_transactions', function (Blueprint $table) {
            // When the requesting personnel's badge was scanned (PRD FR-SPIMS-007). Cleared by any
            // later change to the draft, and expired by withdrawal.identification_timeout_seconds.
            $table->timestampTz('identified_at')->nullable();
        });

        DB::unprepared(<<<'SQL'
            alter table stock_transactions
              add constraint identified_has_personnel check (
                identified_at is null or personnel_id is not null
              );
            SQL);

        // A supervisor's authorisation to issue more than the recorded balance (PRD FR-SPIMS-008).
        // One row per short line, not columns on stock_transaction_lines: each override posts its
        // own single-line adjustment transaction with its own reason (design §5.6.3), and
        // stock_transactions.reason_code is a single column.
        Schema::create('withdrawal_overrides', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transaction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('part_id')->constrained();
            // What the supervisor counted on the shelf.
            $table->decimal('verified_qty', 14, 3);
            // The balance they were shown. Kept for the audit trail; the adjustment is computed
            // again under lock at commit, never from this (design §5.3).
            $table->decimal('recorded_on_hand', 14, 3);
            $table->text('reason_code');
            $table->foreignId('authorised_by_user_id')->constrained('users');
            $table->timestampTz('authorised_at');
            // Filled at commit: the adjustment this override posted, or the time it was discarded
            // because the locked balance already covered the line.
            $table->foreignId('adjustment_transaction_id')->nullable()->constrained('stock_transactions');
            $table->timestampTz('discarded_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->unique(['stock_transaction_id', 'part_id'], 'withdrawal_overrides_transaction_part_unique');
            $table->index('part_id', 'withdrawal_overrides_part_idx');
            $table->index('authorised_by_user_id', 'withdrawal_overrides_authorised_by_idx');
        });

        DB::unprepared(<<<'SQL'
            alter table withdrawal_overrides
              -- PRD FR-SPIMS-008 step 4 offers these two reasons and no others.
              add constraint withdrawal_overrides_reason_code_check
                check (reason_code in ('found_stock', 'unrecorded_receipt')),
              add constraint withdrawal_overrides_verified_qty_positive check (verified_qty > 0),
              add constraint withdrawal_overrides_recorded_on_hand_non_negative check (recorded_on_hand >= 0),
              -- Posted or discarded at commit, never both.
              add constraint withdrawal_overrides_outcome_exclusive check (
                adjustment_transaction_id is null or discarded_at is null
              );
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('withdrawal_overrides');

        DB::unprepared('alter table stock_transactions drop constraint identified_has_personnel');

        Schema::table('stock_transactions', function (Blueprint $table) {
            $table->dropColumn('identified_at');
        });
    }
};
