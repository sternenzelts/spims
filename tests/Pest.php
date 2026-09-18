<?php

use Illuminate\Foundation\Testing\DatabaseTruncation;
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

// Tests whose writes must really commit: another connection or process has to see them, or the code
// under test must run outside a wrapping transaction. They truncate tables instead of rolling back.
pest()->extend(TestCase::class)
    ->use(DatabaseTruncation::class)
    ->in('Committed');

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
