<?php

namespace App\Domain\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;

final class AuditLogger
{
    /** Context keys filled by App\Http\Middleware\CaptureAuditContext on web requests. */
    public const IP_ADDRESS = 'audit.ip_address';

    public const USER_AGENT = 'audit.user_agent';

    /**
     * Append one entry to the configuration audit trail (design §4.6). The actor defaults to the
     * signed-in user; console commands and jobs pass the actor explicitly.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function record(
        string $event,
        Model $auditable,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?User $actor = null,
    ): AuditLog {
        $signedIn = Auth::user();
        $actor ??= $signedIn instanceof User ? $signedIn : null;

        return AuditLog::query()->create([
            'user_id' => $actor?->id,
            'auditable_type' => $auditable->getMorphClass(),
            'auditable_id' => (string) $auditable->getKey(),
            'event' => $event,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => Context::getHidden(self::IP_ADDRESS),
            'user_agent' => Context::getHidden(self::USER_AGENT),
        ]);
    }

    /**
     * Record an update with only the values that changed, and nothing when none did.
     *
     * @param  array<string, mixed>  $old
     * @param  array<string, mixed>  $new
     */
    public function recordChanges(Model $auditable, array $old, array $new, ?User $actor = null): void
    {
        $changed = array_keys(array_filter(
            $new,
            fn (mixed $value, string $key): bool => $value !== ($old[$key] ?? null),
            ARRAY_FILTER_USE_BOTH,
        ));

        if ($changed !== []) {
            $this->record('updated', $auditable, Arr::only($old, $changed), Arr::only($new, $changed), $actor);
        }
    }
}
