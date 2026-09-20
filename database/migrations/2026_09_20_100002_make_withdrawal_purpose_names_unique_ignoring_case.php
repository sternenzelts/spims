<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Two purposes whose names differ only in letter case read the same at the station, so the
        // table refuses them, as PurposeCatalogue already does (Plan 2 Ruling Q).
        DB::unprepared(<<<'SQL'
            alter table withdrawal_purposes drop constraint withdrawal_purposes_name_unique;

            create unique index withdrawal_purposes_name_lower_unique on withdrawal_purposes (lower(name));
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop index withdrawal_purposes_name_lower_unique;

            alter table withdrawal_purposes add constraint withdrawal_purposes_name_unique unique (name);
            SQL);
    }
};
