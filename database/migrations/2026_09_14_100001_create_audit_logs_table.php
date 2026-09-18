<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained();
            $table->text('auditable_type');
            // text, not bigint: settings are keyed by name, e.g. regional.timezone.
            $table->text('auditable_id');
            $table->text('event');
            $table->jsonb('old_values')->nullable();
            $table->jsonb('new_values')->nullable();
            $table->ipAddress()->nullable();
            $table->text('user_agent')->nullable();
            $table->timestampTz('created_at')->useCurrent();
        });

        DB::statement('create index audit_logs_auditable_idx on audit_logs (auditable_type, auditable_id, created_at desc)');
        DB::statement('create index audit_logs_user_idx on audit_logs (user_id, created_at desc)');

        // Shared by every append-only table: audit_logs here, stock_movements later. "or replace"
        // because migrate:fresh drops tables but not functions.
        DB::unprepared(<<<'SQL'
            create or replace function prevent_append_only_mutation() returns trigger
            language plpgsql as $fn$
            begin
              raise exception '% is append-only (attempted % on id %)', tg_table_name, tg_op, old.id;
            end;
            $fn$;

            create trigger audit_logs_append_only
              before update or delete on audit_logs
              for each row execute function prevent_append_only_mutation();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
        DB::statement('drop function if exists prevent_append_only_mutation()');
    }
};
