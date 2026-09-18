<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_transactions', function (Blueprint $table) {
            $table->id();
            // Assigned when the transaction completes (PRD FR-SPIMS-007), so drafts have none.
            $table->text('transaction_no')->nullable()->unique();
            $table->text('type');
            $table->text('status')->default('draft');
            $table->foreignId('custodian_user_id')->constrained('users');
            $table->foreignId('personnel_id')->nullable()->constrained('personnel');
            $table->foreignId('equipment_id')->nullable()->constrained('equipment');
            $table->foreignId('purpose_id')->nullable()->constrained('withdrawal_purposes');
            $table->text('supplier')->nullable();
            $table->text('reference_no')->nullable();
            $table->text('reason_code')->nullable();
            $table->text('remarks')->nullable();
            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at')->nullable();
            $table->boolean('is_backdated')->default(false);
            $table->text('backdate_reason')->nullable();
            $table->foreignId('reverses_transaction_id')->nullable()->constrained('stock_transactions');
            $table->foreignId('reversed_by_user_id')->nullable()->constrained('users');
            $table->text('reversal_reason')->nullable();
            $table->text('identification_method')->nullable();
            $table->foreignId('identification_authorised_by_user_id')->nullable()->constrained('users');
            $table->text('identification_reason')->nullable();
            $table->text('paper_form_no')->nullable()->unique();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::unprepared(<<<'SQL'
            alter table stock_transactions
              add constraint stock_transactions_type_check
                check (type in ('withdrawal', 'replenishment', 'adjustment', 'opening')),
              add constraint stock_transactions_status_check
                check (status in ('draft', 'completed', 'cancelled', 'reversed')),
              add constraint stock_transactions_reason_code_check
                check (reason_code in ('miscount', 'damage', 'unrecorded_issue', 'unrecorded_receipt',
                                       'found_stock', 'shrinkage', 'other')),
              add constraint stock_transactions_identification_method_check
                check (identification_method in ('badge_scan', 'supervisor_manual', 'paper_form')),
              add constraint posted_is_numbered check (
                status in ('draft', 'cancelled') or transaction_no is not null
              ),
              -- The accountability guarantee of proposal §2. Reversals copy attribution from the original.
              add constraint completed_withdrawal_is_attributed check (
                not (status = 'completed' and type = 'withdrawal' and reverses_transaction_id is null)
                or (personnel_id is not null and equipment_id is not null and purpose_id is not null
                    and identification_method is not null)
              ),
              add constraint completed_has_recorded_at check (
                status not in ('completed', 'reversed') or recorded_at is not null
              ),
              add constraint manual_identification_is_authorised check (
                identification_method is distinct from 'supervisor_manual'
                or (identification_authorised_by_user_id is not null and identification_reason is not null)
              ),
              add constraint backdated_has_paper_form check (not is_backdated or paper_form_no is not null),
              add constraint backdated_has_reason check (not is_backdated or backdate_reason is not null),
              add constraint adjustment_has_reason check (
                type <> 'adjustment' or status <> 'completed' or reason_code is not null
              );

            create index stock_transactions_status_type_idx on stock_transactions (status, type);
            create index stock_transactions_occurred_idx on stock_transactions (occurred_at desc);
            create index stock_transactions_personnel_idx on stock_transactions (personnel_id);
            create index stock_transactions_equipment_idx on stock_transactions (equipment_id);
            create index stock_transactions_custodian_idx on stock_transactions (custodian_user_id);
            create index stock_transactions_purpose_idx on stock_transactions (purpose_id);

            -- Indexes the reversal lookup and allows each transaction to be reversed only once.
            create unique index stock_transactions_reverses_key
              on stock_transactions (reverses_transaction_id)
              where reverses_transaction_id is not null;
            SQL);

        Schema::create('stock_transaction_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_transaction_id')->constrained()->cascadeOnDelete();
            $table->integer('line_no');
            $table->foreignId('part_id')->constrained();
            $table->decimal('quantity', 14, 3);
            // -1 removes stock, +1 adds it. Fixed by type except on adjustments (see TransactionType).
            $table->smallInteger('direction');
            $table->foreignId('equipment_id')->nullable()->constrained('equipment');
            $table->foreignId('purpose_id')->nullable()->constrained('withdrawal_purposes');
            $table->decimal('unit_cost', 14, 4)->nullable();
            $table->text('remarks')->nullable();
            $table->unique(['stock_transaction_id', 'line_no']);
            // One line per part; the withdrawal screen merges duplicates.
            $table->unique(['stock_transaction_id', 'part_id']);
            $table->index('part_id', 'stock_transaction_lines_part_idx');
        });

        DB::unprepared(<<<'SQL'
            alter table stock_transaction_lines
              add constraint stock_transaction_lines_quantity_positive check (quantity > 0),
              add constraint stock_transaction_lines_direction_check check (direction in (-1, 1));
            SQL);

        // THE LEDGER. Append-only, signed quantities, balance stamped in id order (design §4.5).
        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('part_id')->constrained();
            $table->foreignId('stock_transaction_id')->constrained();
            $table->foreignId('stock_transaction_line_id')->nullable()->constrained();
            $table->text('movement_type');
            $table->decimal('quantity', 14, 3);
            $table->decimal('balance_after', 14, 3);
            $table->timestampTz('occurred_at');
            $table->timestampTz('recorded_at')->useCurrent();
            $table->foreignId('personnel_id')->nullable()->constrained('personnel');
            $table->foreignId('equipment_id')->nullable()->constrained('equipment');
            $table->foreignId('purpose_id')->nullable()->constrained('withdrawal_purposes');
            $table->foreignId('custodian_user_id')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::unprepared(<<<'SQL'
            alter table stock_movements
              add constraint stock_movements_movement_type_check
                check (movement_type in ('withdrawal', 'replenishment', 'adjustment', 'opening', 'count_variance')),
              add constraint stock_movements_quantity_non_zero check (quantity <> 0);

            create index stock_movements_part_occurred_idx on stock_movements (part_id, occurred_at desc);
            create index stock_movements_occurred_idx on stock_movements (occurred_at desc);
            create index stock_movements_personnel_idx on stock_movements (personnel_id, occurred_at desc);
            create index stock_movements_equipment_idx on stock_movements (equipment_id, occurred_at desc);
            create index stock_movements_txn_idx on stock_movements (stock_transaction_id);
            create index stock_movements_purpose_idx on stock_movements (purpose_id);

            -- Consumption, depletion and monthly reports read withdrawals only.
            create index stock_movements_withdrawals_idx
              on stock_movements (part_id, occurred_at)
              where movement_type = 'withdrawal';

            -- Opening balances cannot be reversed, so a part has at most one (PRD FR-SPIMS-005).
            create unique index stock_movements_one_opening_per_part
              on stock_movements (part_id)
              where movement_type = 'opening';

            create trigger stock_movements_append_only
              before update or delete on stock_movements
              for each row execute function prevent_append_only_mutation();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_movements');
        Schema::dropIfExists('stock_transaction_lines');
        Schema::dropIfExists('stock_transactions');
    }
};
