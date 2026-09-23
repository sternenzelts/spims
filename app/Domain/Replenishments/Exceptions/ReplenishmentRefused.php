<?php

namespace App\Domain\Replenishments\Exceptions;

use App\Models\Part;
use App\Models\StockTransaction;
use RuntimeException;

/**
 * A rule of PRD FR-SPIMS-009 stopped a change to a draft replenishment, or stopped its
 * confirmation.
 */
final class ReplenishmentRefused extends RuntimeException
{
    public static function notADraft(StockTransaction $transaction): self
    {
        return new self(__('This replenishment is :status and can no longer be changed.', [
            'status' => $transaction->status->value,
        ]));
    }

    public static function inactivePart(Part $part): self
    {
        return new self(__('Spare part :part is Inactive. Reactivate it before receiving stock.', [
            'part' => $part->part_number,
        ]));
    }

    public static function quantityNotPositive(string $quantity): self
    {
        return new self(__('Quantity :quantity is not a number greater than zero.', ['quantity' => trim($quantity)]));
    }

    public static function unitCostNegative(string $unitCost): self
    {
        return new self(__('A unit cost of :cost is not a number of zero or more.', ['cost' => trim($unitCost)]));
    }

    public static function lineNotOnDraft(): self
    {
        return new self(__('That line is not part of this replenishment.'));
    }

    public static function supplierRequired(): self
    {
        return new self(__('Supplier is required.'));
    }

    public static function referenceRequired(): self
    {
        return new self(__('A delivery receipt or purchase order reference is required.'));
    }

    public static function receivedInFuture(): self
    {
        return new self(__('The date received cannot be in the future.'));
    }

    public static function receivedEarlier(): self
    {
        return new self(__('Record a delivery received on an earlier day as a backdated entry, on the paper fallback screen.'));
    }

    public static function noLines(): self
    {
        return new self(__('Add at least one spare part received before confirming.'));
    }
}
