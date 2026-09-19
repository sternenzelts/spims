<?php

namespace App\Domain\Parts\Exceptions;

use App\Models\Part;
use DomainException;

/**
 * PRD FR-SPIMS-001: a part number that already exists, ignoring case, blocks creation and links to
 * the existing record.
 */
final class DuplicatePartNumber extends DomainException
{
    public function __construct(public readonly Part $existing)
    {
        parent::__construct("Part number {$existing->part_number} already exists.");
    }
}
