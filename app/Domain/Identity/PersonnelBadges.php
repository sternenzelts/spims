<?php

namespace App\Domain\Identity;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Exceptions\BadgeAlreadyEnrolled;
use App\Domain\Identity\Exceptions\InvalidBadgeInput;
use App\Models\Personnel;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class PersonnelBadges
{
    public function __construct(
        private BadgeReader $reader,
        private BadgeIdentity $identity,
        private AuditLogger $audit,
    ) {}

    /**
     * Enroll a scanned badge to a person (PRD FR-SPIMS-004). A badge still held by an Inactive
     * person is released from them first, because badges are re-issued when people leave.
     *
     * @throws InvalidBadgeInput
     * @throws BadgeAlreadyEnrolled when an Active person holds the badge
     */
    public function enroll(Personnel $personnel, string $rawInput, User $actor): void
    {
        $uid = $this->reader->normalise($rawInput);
        $attributes = $this->identity->attributesFor($uid);

        DB::transaction(function () use ($personnel, $attributes, $actor): void {
            $holder = Personnel::query()
                ->where('badge_uid_hash', $attributes['badge_uid_hash'])
                ->lockForUpdate()
                ->first();

            if ($holder !== null && $holder->isNot($personnel)) {
                if ($holder->is_active) {
                    throw new BadgeAlreadyEnrolled($holder);
                }

                $released = $holder->badge_uid_masked;
                $holder->forceFill(['badge_uid_hash' => null, 'badge_uid_encrypted' => null, 'badge_uid_masked' => null])->save();
                $this->audit->record('badge_released', $holder, ['badge_uid_masked' => $released], null, $actor);
            }

            $previous = $personnel->badge_uid_masked;
            $personnel->forceFill($attributes)->save();

            $this->audit->record(
                $previous === null ? 'badge_enrolled' : 'badge_replaced',
                $personnel,
                ['badge_uid_masked' => $previous],
                ['badge_uid_masked' => $attributes['badge_uid_masked']],
                $actor,
            );
        });
    }

    /**
     * The Active person a scan identifies, or null when the badge is not enrolled or its holder is
     * Inactive (PRD FR-SPIMS-007).
     *
     * @throws InvalidBadgeInput
     */
    public function identify(string $rawInput): ?Personnel
    {
        return Personnel::query()
            ->where('badge_uid_hash', $this->identity->hash($this->reader->normalise($rawInput)))
            ->where('is_active', true)
            ->first();
    }
}
