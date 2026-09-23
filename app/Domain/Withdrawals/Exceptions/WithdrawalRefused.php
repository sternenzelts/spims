<?php

namespace App\Domain\Withdrawals\Exceptions;

use App\Models\Equipment;
use App\Models\Part;
use App\Models\Personnel;
use App\Models\StockTransaction;
use App\Models\User;
use App\Models\WithdrawalPurpose;
use RuntimeException;

/**
 * A rule of PRD FR-SPIMS-006, 007 or 008 stopped a change to a draft withdrawal, or stopped its
 * confirmation. The message is shown to the custodian at the counter, so it names the part, the
 * equipment or the purpose at fault rather than describing the rule.
 */
final class WithdrawalRefused extends RuntimeException
{
    public static function notADraft(StockTransaction $transaction): self
    {
        return new self(__('This withdrawal is :status and can no longer be changed.', [
            'status' => $transaction->status->value,
        ]));
    }

    public static function inactivePart(Part $part): self
    {
        return new self(__('Spare part :part is Inactive and cannot be withdrawn.', [
            'part' => $part->part_number,
        ]));
    }

    public static function inactiveEquipment(Equipment $equipment): self
    {
        return new self(__('Equipment :equipment is Inactive.', ['equipment' => $equipment->code]));
    }

    public static function inactivePurpose(WithdrawalPurpose $purpose): self
    {
        return new self(__('Withdrawal purpose :purpose is Inactive.', ['purpose' => $purpose->name]));
    }

    public static function quantityNotPositive(string $quantity): self
    {
        return new self(__('Quantity :quantity is not a number greater than zero.', ['quantity' => trim($quantity)]));
    }

    public static function lineNotOnDraft(): self
    {
        return new self(__('That line is not part of this withdrawal.'));
    }

    public static function noLines(): self
    {
        return new self(__('Add at least one spare part before confirming.'));
    }

    public static function equipmentRequired(): self
    {
        return new self(__('Equipment is required before a withdrawal can be confirmed.'));
    }

    public static function purposeRequired(): self
    {
        return new self(__('A withdrawal purpose is required before a withdrawal can be confirmed.'));
    }

    public static function remarksRequired(WithdrawalPurpose $purpose): self
    {
        return new self(__('Remarks are required for the purpose :purpose.', ['purpose' => $purpose->name]));
    }

    public static function badgeUnreadable(): self
    {
        return new self(__('That badge could not be read. Scan it again, or ask a supervisor to identify the person.'));
    }

    public static function badgeNotRecognised(): self
    {
        return new self(__('That badge is not recognised. It may not be enrolled, or the person may no longer be Active.'));
    }

    public static function scanningTooFast(int $seconds): self
    {
        return new self(__('Too many badge scans. Wait :seconds seconds and try again.', ['seconds' => $seconds]));
    }

    public static function notIdentified(): self
    {
        return new self(__('Ask the person receiving the parts to scan their badge before confirming.'));
    }

    public static function identificationExpired(int $seconds): self
    {
        return new self(__('The badge scan expired after :seconds seconds. Ask for a new scan.', ['seconds' => $seconds]));
    }

    public static function notAllowedToIdentify(User $user): self
    {
        return new self(__(':name is not allowed to identify someone without a badge.', ['name' => $user->name]));
    }

    public static function identificationReasonRequired(): self
    {
        return new self(__('Give the reason the badge could not be scanned.'));
    }

    public static function inactivePersonnel(Personnel $personnel): self
    {
        return new self(__(':name is no longer Active and cannot receive spare parts.', [
            'name' => $personnel->full_name,
        ]));
    }
}
