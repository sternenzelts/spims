<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Exact and leading part-number lookups (NFR-SPIMS-006: under 150 ms at p95). The unique index
        // on lower(part_number) follows the database collation, which cannot serve LIKE 'abc%'.
        DB::statement('create index parts_part_number_prefix_idx on parts (lower(part_number) text_pattern_ops)');
    }

    public function down(): void
    {
        DB::statement('drop index if exists parts_part_number_prefix_idx');
    }
};
