<?php

use App\Models\WithdrawalPurpose;
use Illuminate\Database\UniqueConstraintViolationException;

it('treats withdrawal purpose names as unique regardless of letter case', function () {
    WithdrawalPurpose::query()->create(['code' => 'machine_repair', 'name' => 'Machine repair']);

    expect(inSavepoint(fn () => WithdrawalPurpose::query()->create(['code' => 'machine_repair_2', 'name' => 'machine repair'])))
        ->toThrow(UniqueConstraintViolationException::class);
});
