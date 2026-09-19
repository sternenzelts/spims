<?php

use App\Enums\Criticality;
use App\Enums\Role;
use App\Livewire\Parts\StockLevelsForm;
use App\Models\AuditLog;
use App\Models\Part;
use Livewire\Livewire;

it('lets only planners and administrators set stock levels', function (Role $role, bool $allowed) {
    $part = Part::factory()->create();

    $this->actingAs(userWithRoles($role))->get(route('parts.stock-levels', $part))->assertStatus($allowed ? 200 : 403);
})->with(roleMatrix('--PA'));

it('saves levels, criticality and lead time, recording old and new values', function () {
    $planner = userWithRoles(Role::Planner);
    $part = Part::factory()->create()->refresh();

    Livewire::actingAs($planner)->test(StockLevelsForm::class, ['part' => $part])
        ->set('reorder_point', '10')
        ->set('min_level', '4')
        ->set('critical_level', '1.5')
        ->set('max_level', '30')
        ->set('criticality', 'critical')
        ->set('lead_time_days', '21')
        ->call('save')
        ->assertHasNoErrors();

    expect($part->refresh())
        ->reorder_point->toBe('10.000')
        ->min_level->toBe('4.000')
        ->critical_level->toBe('1.500')
        ->max_level->toBe('30.000')
        ->criticality->toBe(Criticality::Critical)
        ->lead_time_days->toBe(21)
        ->and(AuditLog::query()->where('auditable_type', 'part')->where('event', 'updated')->sole())
        ->user_id->toBe($planner->id)
        ->old_values->toBe([
            'max_level' => null,
            'min_level' => '0.000',
            'criticality' => 'normal',
            'reorder_point' => '0.000',
            'critical_level' => '0.000',
            'lead_time_days' => null,
        ])
        ->new_values->toBe([
            'max_level' => '30.000',
            'min_level' => '4.000',
            'criticality' => 'critical',
            'reorder_point' => '10.000',
            'critical_level' => '1.500',
            'lead_time_days' => 21,
        ]);
});

it('refuses a critical level above the minimum, and a maximum below it', function () {
    $part = Part::factory()->create()->refresh();

    Livewire::actingAs(userWithRoles(Role::Admin))->test(StockLevelsForm::class, ['part' => $part])
        ->set('min_level', '5')
        ->set('critical_level', '6')
        ->set('max_level', '4')
        ->call('save')
        ->assertHasErrors(['critical_level' => 'lte', 'max_level' => 'gte']);
});

it('warns before saving a reorder point below the minimum, then saves once confirmed', function () {
    $part = Part::factory()->create()->refresh();

    $screen = Livewire::actingAs(userWithRoles(Role::Planner))->test(StockLevelsForm::class, ['part' => $part])
        ->set('min_level', '5')
        ->set('reorder_point', '3')
        ->assertSee('below the minimum level')
        ->call('save')
        ->assertHasErrors('confirmWarnings');

    expect($part->refresh()->reorder_point)->toBe('0.000');

    $screen->set('confirmWarnings', true)->call('save')->assertHasNoErrors();

    expect($part->refresh()->reorder_point)->toBe('3.000');
});

it('warns about a reorder point above the maximum', function () {
    $part = Part::factory()->create()->refresh();

    Livewire::actingAs(userWithRoles(Role::Planner))->test(StockLevelsForm::class, ['part' => $part])
        ->set('min_level', '2')
        ->set('reorder_point', '12')
        ->set('max_level', '10')
        ->call('save')
        ->assertHasErrors('confirmWarnings')
        ->assertSee('above the maximum level');
});

it('accepts quantities to three decimal places and whole-day lead times only', function () {
    $part = Part::factory()->create()->refresh();

    Livewire::actingAs(userWithRoles(Role::Planner))->test(StockLevelsForm::class, ['part' => $part])
        ->set('min_level', '0.5')
        ->set('reorder_point', '0.7505')
        ->set('lead_time_days', '2.5')
        ->call('save')
        ->assertHasErrors(['reorder_point' => 'decimal', 'lead_time_days' => 'integer']);
});

it('shows the change history to supervisors, planners and administrators only', function (Role $role, bool $allowed) {
    $part = Part::factory()->create()->refresh();

    Livewire::actingAs(userWithRoles(Role::Planner))->test(StockLevelsForm::class, ['part' => $part])
        ->set('reorder_point', '8')
        ->call('save')
        ->assertHasNoErrors();

    $response = $this->actingAs(userWithRoles($role))->get(route('parts.show', $part));

    $allowed
        ? $response->assertSee('Change history')->assertSee('8.000')
        : $response->assertDontSee('Change history');
})->with(roleMatrix('-SPA'));
