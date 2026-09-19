<?php

namespace App\Domain\Parts\Exceptions;

use DomainException;

/**
 * A change to a spare part record that PRD FR-SPIMS-001 forbids. $field names the input the refusal
 * is about.
 */
final class PartChangeRefused extends DomainException
{
    private function __construct(string $message, public readonly string $field)
    {
        parent::__construct($message);
    }

    public static function inactiveLocation(): self
    {
        return new self('Choose an Active storage location.', 'default_location_id');
    }

    public static function unitOfMeasureLocked(): self
    {
        return new self(
            'The unit of measure cannot change once the part has stock movements: it would change the meaning of every recorded quantity.',
            'unit_of_measure',
        );
    }
}
