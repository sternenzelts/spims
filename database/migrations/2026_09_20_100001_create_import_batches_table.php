<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One uploaded spare part file, from upload to import (PRD FR-SPIMS-005, §7.7).
        Schema::create('import_batches', function (Blueprint $table) {
            $table->id();
            $table->text('filename');
            $table->text('format');
            $table->text('storage_path');
            $table->text('status')->default('pending');
            $table->jsonb('headers')->default('[]');
            $table->jsonb('column_map')->default('{}');
            $table->integer('rows_total')->default(0);
            $table->integer('rows_created')->default(0);
            $table->integer('rows_updated')->default(0);
            $table->integer('rows_failed')->default(0);
            $table->integer('opening_balances')->default(0);
            $table->jsonb('errors')->default('[]');
            $table->jsonb('warnings')->default('[]');
            $table->jsonb('preview')->default('[]');
            $table->foreignId('imported_by_user_id')->constrained('users');
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->timestampTz('confirmed_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
        });

        DB::unprepared(<<<'SQL'
            alter table import_batches
              add constraint import_batches_status_check
                check (status in ('pending', 'validated', 'completed', 'failed', 'cancelled')),
              add constraint import_batches_format_check check (format in ('csv', 'xlsx'));

            create index import_batches_created_idx on import_batches (created_at desc);
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('import_batches');
    }
};
