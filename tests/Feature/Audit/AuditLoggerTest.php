<?php

use App\Domain\Audit\AuditLogger;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;

it('records the event, the audited record, old and new values and the actor', function () {
    $actor = User::factory()->create();
    $subject = User::factory()->create(['name' => 'Juan Dela Cruz']);

    $log = app(AuditLogger::class)->record('updated', $subject, ['name' => 'Juan'], ['name' => 'Juan Dela Cruz'], $actor);

    expect($log->fresh())
        ->user_id->toBe($actor->id)
        ->auditable_type->toBe('user')
        ->auditable_id->toBe((string) $subject->id)
        ->event->toBe('updated')
        ->old_values->toBe(['name' => 'Juan'])
        ->new_values->toBe(['name' => 'Juan Dela Cruz']);
});

it('defaults the actor to the signed-in user', function () {
    $user = User::factory()->create();

    $this->actingAs($user);

    expect(app(AuditLogger::class)->record('updated', $user)->user_id)->toBe($user->id);
});

it('captures the client address and browser on web requests', function () {
    $user = User::factory()->create();

    Route::middleware('web')->get('/_test/audit', function () use ($user) {
        app(AuditLogger::class)->record('updated', $user);

        return response()->noContent();
    });

    $this->withServerVariables(['REMOTE_ADDR' => '10.20.30.40'])
        ->withHeader('User-Agent', 'Storeroom Station 1')
        ->get('/_test/audit')
        ->assertNoContent();

    expect(AuditLog::query()->sole())
        ->ip_address->toBe('10.20.30.40')
        ->user_agent->toBe('Storeroom Station 1');
});

it('cannot be changed or deleted once written', function () {
    $log = app(AuditLogger::class)->record('updated', User::factory()->create());

    expect(inSavepoint(fn () => $log->update(['event' => 'tampered'])))
        ->toThrow(QueryException::class, 'audit_logs is append-only')
        ->and(inSavepoint(fn () => $log->delete()))
        ->toThrow(QueryException::class, 'audit_logs is append-only');
});
