<?php

use App\Models\WithdrawalPurpose;
use Database\Seeders\WithdrawalPurposeSeeder;

it('seeds the proposal purpose list in order', function () {
    expect(WithdrawalPurpose::query()->orderBy('sort_order')->pluck('name')->all())->toBe([
        'Preventive Maintenance',
        'Corrective Maintenance',
        'Breakdown Repair',
        'Machine Modification',
        'Replacement of Worn Part',
        'Other Maintenance Activity',
    ])->and(WithdrawalPurpose::query()->where('requires_remarks', true)->pluck('code')->all())->toBe(['other']);
});

it('keeps renamed purposes when the seeder runs again', function () {
    WithdrawalPurpose::query()->where('code', 'breakdown_repair')->update(['name' => 'Emergency Breakdown']);

    $this->seed(WithdrawalPurposeSeeder::class);

    expect(WithdrawalPurpose::query()->count())->toBe(6)
        ->and(WithdrawalPurpose::query()->where('code', 'breakdown_repair')->value('name'))->toBe('Emergency Breakdown');
});
