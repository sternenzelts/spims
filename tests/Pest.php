<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

/**
 * Wrap a statement that is expected to fail in a savepoint. Postgres aborts the whole test
 * transaction after an error, so without this every later query in the test fails too.
 */
function inSavepoint(Closure $callback): Closure
{
    return fn () => DB::transaction($callback);
}
