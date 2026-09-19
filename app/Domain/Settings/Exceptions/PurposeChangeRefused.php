<?php

namespace App\Domain\Settings\Exceptions;

use App\Models\WithdrawalPurpose;
use DomainException;

/**
 * A change to the withdrawal purposes that PRD FR-SPIMS-018 forbids. $field names the input the
 * refusal is about.
 */
final class PurposeChangeRefused extends DomainException
{
    private function __construct(string $message, public readonly string $field)
    {
        parent::__construct($message);
    }

    public static function duplicateName(string $name): self
    {
        return new self("A withdrawal purpose named {$name} already exists.", 'name');
    }

    public static function lastActive(): self
    {
        return new self('At least one withdrawal purpose must stay Active.', 'is_active');
    }

    public static function used(WithdrawalPurpose $purpose): self
    {
        return new self("{$purpose->name} has been used on transactions. Rename it or set it to Inactive instead.", 'purposes');
    }
}
