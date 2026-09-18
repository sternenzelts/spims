<?php

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

it('runs the suite on PostgreSQL with emulated prepares, as on the Supabase pooler', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql')
        ->and(config('database.connections.pgsql.options'))->toHaveKey(PDO::ATTR_EMULATE_PREPARES, true);
});

it('keeps the database session in UTC so saved instants are not shifted', function () {
    $manilaMorning = CarbonImmutable::parse('2026-09-14 08:30:00', 'Asia/Manila');

    expect(DB::scalar('show timezone'))->toBe('UTC')
        ->and(DB::scalar('select cast(? as timestamptz) = cast(? as timestamptz)', [
            $manilaMorning->utc(),
            '2026-09-14 00:30:00+00',
        ]))->toBeTrue();
});
