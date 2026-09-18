<?php

use Illuminate\Support\Facades\DB;

it('stores every timestamp as timestamptz, never bare timestamp', function () {
    $bareTimestamps = collect(DB::select(<<<'SQL'
        select table_name, column_name
        from information_schema.columns
        where table_schema = current_schema()
          and data_type = 'timestamp without time zone'
        order by table_name, column_name
        SQL))
        ->map(fn (object $column): string => "{$column->table_name}.{$column->column_name}")
        ->all();

    expect($bareTimestamps)->toBe([]);
});

it('keeps timestamptz to the microsecond, so database defaults are never rounded to the next second', function () {
    $roundedTimestamps = collect(DB::select(<<<'SQL'
        select table_name, column_name
        from information_schema.columns
        where table_schema = current_schema()
          and data_type = 'timestamp with time zone'
          and datetime_precision <> 6
        order by table_name, column_name
        SQL))
        ->map(fn (object $column): string => "{$column->table_name}.{$column->column_name}")
        ->all();

    expect($roundedTimestamps)->toBe([]);
});

it('uses text rather than varchar in every SPIMS table', function () {
    // Created by Laravel, the starter kit and packages: they keep their published column types.
    // A new package's tables fail here until they are added, deliberately.
    $frameworkTables = [
        'cache', 'cache_locks', 'failed_jobs', 'job_batches', 'jobs', 'migrations', 'passkeys',
        'password_reset_tokens', 'sessions', 'users',
        'model_has_permissions', 'model_has_roles', 'permissions', 'roles',
    ];

    $varcharColumns = collect(DB::select(<<<'SQL'
        select table_name, column_name
        from information_schema.columns
        where table_schema = current_schema()
          and data_type = 'character varying'
        order by table_name, column_name
        SQL))
        ->reject(fn (object $column): bool => in_array($column->table_name, $frameworkTables, true))
        ->map(fn (object $column): string => "{$column->table_name}.{$column->column_name}")
        ->values()
        ->all();

    expect($varcharColumns)->toBe([]);
});
