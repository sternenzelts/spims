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
