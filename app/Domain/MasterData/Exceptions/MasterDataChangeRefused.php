<?php

namespace App\Domain\MasterData\Exceptions;

use App\Models\StorageLocation;
use DomainException;

/**
 * A change to storage locations or equipment that PRD FR-SPIMS-003 forbids. $field names the input
 * the refusal is about.
 */
final class MasterDataChangeRefused extends DomainException
{
    private function __construct(string $message, public readonly string $field)
    {
        parent::__construct($message);
    }

    public static function duplicateCode(string $code): self
    {
        return new self("Code {$code} is already in use.", 'code');
    }

    /**
     * @param  list<string>  $partNumbers
     */
    public static function locationHoldsActiveParts(StorageLocation $location, array $partNumbers): self
    {
        $shown = implode(', ', array_slice($partNumbers, 0, 10));
        $more = count($partNumbers) > 10 ? ' and '.(count($partNumbers) - 10).' more' : '';

        return new self(
            "{$location->code} holds Active spare parts: {$shown}{$more}. Move them to another location or set them to Inactive first.",
            'is_active',
        );
    }
}
