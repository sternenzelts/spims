<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('create extension if not exists pg_trgm');

        Schema::create('storage_locations', function (Blueprint $table) {
            $table->id();
            $table->text('code')->unique();
            $table->text('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        Schema::create('equipment', function (Blueprint $table) {
            $table->id();
            $table->text('code')->unique();
            $table->text('name');
            $table->text('area')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        Schema::create('parts', function (Blueprint $table) {
            $table->id();
            $table->text('part_number');
            $table->text('description');
            $table->text('unit_of_measure')->default('pcs');
            $table->foreignId('default_location_id')->nullable()->constrained('storage_locations');
            $table->decimal('min_level', 14, 3)->default(0);
            $table->decimal('max_level', 14, 3)->nullable();
            $table->decimal('reorder_point', 14, 3)->default(0);
            $table->decimal('critical_level', 14, 3)->default(0);
            $table->text('criticality')->default('normal');
            $table->integer('lead_time_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::unprepared(<<<'SQL'
            alter table parts
              add constraint parts_criticality_check check (criticality in ('critical', 'important', 'normal')),
              add constraint parts_levels_ordered check (
                critical_level <= min_level and (max_level is null or max_level >= min_level)
              ),
              add constraint parts_levels_non_negative check (
                min_level >= 0 and reorder_point >= 0 and critical_level >= 0
              );

            create unique index parts_part_number_key on parts (lower(part_number));
            create index parts_description_trgm on parts using gin (description gin_trgm_ops);
            create index parts_criticality_active_idx on parts (criticality) where is_active;
            create index parts_location_idx on parts (default_location_id);
            SQL);

        // A part usually fits several machines (design §4.3). Distinct from where a part was used.
        Schema::create('part_equipment', function (Blueprint $table) {
            $table->foreignId('part_id')->constrained()->cascadeOnDelete();
            $table->foreignId('equipment_id')->constrained('equipment')->cascadeOnDelete();
            $table->text('notes')->nullable();
            $table->primary(['part_id', 'equipment_id']);
            $table->index('equipment_id', 'part_equipment_equipment_idx');
        });

        // The stock cache and row-lock target: one row per part (design §4.1).
        Schema::create('part_stock', function (Blueprint $table) {
            $table->foreignId('part_id')->primary()->constrained()->restrictOnDelete();
            $table->decimal('on_hand', 14, 3)->default(0);
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::unprepared(<<<'SQL'
            alter table part_stock add constraint part_stock_non_negative check (on_hand >= 0);

            -- Every part gets its lock target the moment it exists, however it was inserted.
            -- "or replace" because migrate:fresh drops tables but not functions.
            create or replace function create_part_stock_row() returns trigger
            language plpgsql as $fn$
            begin
              insert into part_stock (part_id) values (new.id);
              return new;
            end;
            $fn$;

            create trigger parts_create_stock_row
              after insert on parts
              for each row execute function create_part_stock_row();
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('part_stock');
        Schema::dropIfExists('part_equipment');
        Schema::dropIfExists('parts');
        DB::statement('drop function if exists create_part_stock_row()');
        Schema::dropIfExists('equipment');
        Schema::dropIfExists('storage_locations');
    }
};
