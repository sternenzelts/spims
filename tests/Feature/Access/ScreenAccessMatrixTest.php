<?php

use App\Enums\Role;
use App\Models\Equipment;
use App\Models\ImportBatch;
use App\Models\Part;
use App\Models\Personnel;
use App\Models\StorageLocation;
use App\Models\User;

// Every Plan 2 and Plan 2b screen and the roles PRD §8 lets open it. Holders read as in RolePermissionMatrixTest:
// C Custodian, S Supervisor, P Planner, A System Administrator. Route parameters are built lazily,
// inside each test's database transaction.
$screens = [
    'Users' => ['users.index', null, '---A'],
    'New user' => ['users.create', null, '---A'],
    'Edit user' => ['users.edit', fn () => ['user' => User::factory()->create()], '---A'],
    'Install settings' => ['install-settings.edit', null, '---A'],
    'Withdrawal purposes' => ['withdrawal-purposes.index', null, '---A'],
    'Storage locations' => ['storage-locations.index', null, '-SPA'],
    'New storage location' => ['storage-locations.create', null, '-SPA'],
    'Edit storage location' => ['storage-locations.edit', fn () => ['location' => StorageLocation::factory()->create()], '-SPA'],
    'Equipment' => ['equipment.index', null, '-SPA'],
    'New equipment' => ['equipment.create', null, '-SPA'],
    'Edit equipment' => ['equipment.edit', fn () => ['equipment' => Equipment::factory()->create()], '-SPA'],
    'Spare parts' => ['parts.index', null, 'CSPA'],
    'Spare part' => ['parts.show', fn () => ['part' => Part::factory()->create()], 'CSPA'],
    'New spare part' => ['parts.create', null, '-SPA'],
    'Edit spare part' => ['parts.edit', fn () => ['part' => Part::factory()->create()], 'CSPA'],
    'Stock levels' => ['parts.stock-levels', fn () => ['part' => Part::factory()->create()], '--PA'],
    'Personnel' => ['personnel.index', null, '-S-A'],
    'Register personnel' => ['personnel.create', null, '-S-A'],
    'Edit personnel' => ['personnel.edit', fn () => ['personnel' => Personnel::factory()->create()], '-S-A'],
    'Spare part imports' => ['imports.index', null, '-S-A'],
    'New spare part import' => ['imports.create', null, '-S-A'],
    'Import template' => ['imports.template', null, '-S-A'],
    'Spare part import' => ['imports.show', fn () => ['batch' => ImportBatch::factory()->create()], '-S-A'],
];

dataset('screens', function () use ($screens) {
    foreach ($screens as $screen => [$route, $parameters]) {
        yield $screen => [$route, $parameters];
    }
});

dataset('screens by role', function () use ($screens) {
    foreach ($screens as $screen => [$route, $parameters, $holders]) {
        foreach (roleMatrix($holders) as $label => [$role, $allowed]) {
            yield "{$screen}, {$label}" => [$route, $parameters, $role, $allowed];
        }
    }
});

it('opens each screen to exactly the roles PRD §8 allows', function (string $route, ?Closure $parameters, Role $role, bool $allowed) {
    $this->actingAs(userWithRoles($role))
        ->get(route($route, $parameters === null ? [] : $parameters()))
        ->assertStatus($allowed ? 200 : 403);
})->with('screens by role');

it('sends guests to sign in', function (string $route, ?Closure $parameters) {
    $this->get(route($route, $parameters === null ? [] : $parameters()))->assertRedirect(route('login'));
})->with('screens');

it('opens nothing to a signed-in user who holds no role', function (string $route, ?Closure $parameters) {
    $this->actingAs(User::factory()->create())
        ->get(route($route, $parameters === null ? [] : $parameters()))
        ->assertForbidden();
})->with('screens');
