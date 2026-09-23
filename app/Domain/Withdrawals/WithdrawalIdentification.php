<?php

namespace App\Domain\Withdrawals;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\BadgeIdentity;
use App\Domain\Identity\BadgeReader;
use App\Domain\Identity\Exceptions\InvalidBadgeInput;
use App\Domain\Identity\PersonnelBadges;
use App\Domain\Settings\InstallSettings;
use App\Domain\Withdrawals\Exceptions\WithdrawalRefused;
use App\Enums\IdentificationMethod;
use App\Enums\Permission;
use App\Enums\TransactionStatus;
use App\Models\Personnel;
use App\Models\StockTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\RateLimiter;
use UnexpectedValueException;

/**
 * Step 5 of design §5.1: the requesting personnel scans their badge, last, and the custodian sees
 * who the system thinks is standing at the counter (PRD FR-SPIMS-007). The raw badge UID never
 * leaves this class — a rejected scan is recorded masked, and an unreadable one not at all.
 */
final readonly class WithdrawalIdentification
{
    /** A stuck keyboard-wedge reader can repeat forever; this is per signed-in custodian. */
    public const int MAX_SCANS_PER_MINUTE = 20;

    public function __construct(
        private PersonnelBadges $badges,
        private BadgeReader $reader,
        private BadgeIdentity $identity,
        private InstallSettings $settings,
        private AuditLogger $audit,
    ) {}

    /**
     * Identify the person a scan belongs to and attach them to the draft.
     *
     * @throws WithdrawalRefused
     */
    public function identify(StockTransaction $draft, string $rawBadge, User $actor): Personnel
    {
        if ($draft->status !== TransactionStatus::Draft) {
            throw WithdrawalRefused::notADraft($draft);
        }

        $key = 'badge-scan:'.$actor->id;

        if (RateLimiter::tooManyAttempts($key, self::MAX_SCANS_PER_MINUTE)) {
            throw WithdrawalRefused::scanningTooFast(RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key);

        try {
            // Normalising here as well as inside PersonnelBadges is safe — the canonical form
            // normalises to itself — and it is what lets a rejected scan be recorded masked.
            $uid = $this->reader->normalise($rawBadge);
        } catch (InvalidBadgeInput) {
            $this->reject($draft, 'unreadable', null, $actor);

            throw WithdrawalRefused::badgeUnreadable();
        }

        $personnel = $this->badges->identify($uid);

        if (! $personnel instanceof Personnel) {
            $this->reject($draft, 'not_recognised', $this->identity->mask($uid), $actor);

            throw WithdrawalRefused::badgeNotRecognised();
        }

        $draft->forceFill([
            'personnel_id' => $personnel->id,
            'identification_method' => IdentificationMethod::BadgeScan,
            'identification_authorised_by_user_id' => null,
            'identification_reason' => null,
            'identified_at' => CarbonImmutable::now(),
        ])->save();

        return $personnel;
    }

    /**
     * FR-SPIMS-007 alternate flow: the person has no badge, or the reader has failed, and an
     * Inventory Supervisor identifies them by hand with a reason. The withdrawal is marked
     * supervisor_manual and appears in the Plan 6 Exceptions report. Pending Microtech's answer to
     * Q12.
     *
     * @throws WithdrawalRefused
     */
    public function identifyManually(
        StockTransaction $draft,
        Personnel $personnel,
        User $supervisor,
        string $reason,
    ): void {
        if ($draft->status !== TransactionStatus::Draft) {
            throw WithdrawalRefused::notADraft($draft);
        }

        if (! $supervisor->can(Permission::IdentificationAuthorise)) {
            throw WithdrawalRefused::notAllowedToIdentify($supervisor);
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw WithdrawalRefused::identificationReasonRequired();
        }

        if (! $personnel->is_active) {
            throw WithdrawalRefused::inactivePersonnel($personnel);
        }

        $draft->forceFill([
            'personnel_id' => $personnel->id,
            'identification_method' => IdentificationMethod::SupervisorManual,
            'identification_authorised_by_user_id' => $supervisor->id,
            'identification_reason' => $reason,
            'identified_at' => CarbonImmutable::now(),
        ])->save();

        $this->audit->record('identified_manually', $draft, null, [
            'reason' => $reason,
            'personnel_id' => $personnel->id,
            'identification_method' => IdentificationMethod::SupervisorManual->value,
        ], $supervisor);
    }

    /**
     * The single place a draft's identification is cleared. Reached from
     * WithdrawalService::invalidate() whenever the draft changes, and directly when the person at
     * the counter is not the one on screen (FR-SPIMS-007 alternate flow).
     */
    public function clear(StockTransaction $draft): void
    {
        if ($draft->identified_at === null && $draft->personnel_id === null) {
            return;
        }

        $draft->forceFill([
            'identified_at' => null,
            'personnel_id' => null,
            'identification_method' => null,
            'identification_authorised_by_user_id' => null,
            'identification_reason' => null,
        ])->save();
    }

    /**
     * When this draft's identification stops being good enough to confirm on.
     */
    public function expiresAt(StockTransaction $draft): ?CarbonImmutable
    {
        return $draft->identified_at?->addSeconds($this->timeoutSeconds());
    }

    public function isCurrent(StockTransaction $draft): bool
    {
        $expiresAt = $this->expiresAt($draft);

        return $expiresAt instanceof CarbonImmutable && $expiresAt->isFuture();
    }

    public function timeoutSeconds(): int
    {
        $seconds = $this->settings->get('withdrawal.identification_timeout_seconds');

        if (! is_int($seconds)) {
            throw new UnexpectedValueException('Install setting [withdrawal.identification_timeout_seconds] must be an integer.');
        }

        return $seconds;
    }

    /**
     * NFR-SPIMS-004: a scan that failed is worth keeping, but only in its masked form.
     */
    private function reject(StockTransaction $draft, string $reason, ?string $masked, User $actor): void
    {
        $values = ['reason' => $reason];

        if ($masked !== null) {
            $values['badge_uid_masked'] = $masked;
        }

        $this->audit->record('badge_scan_rejected', $draft, null, $values, $actor);
    }
}
