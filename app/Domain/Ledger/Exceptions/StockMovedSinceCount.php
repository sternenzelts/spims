<?php

namespace App\Domain\Ledger\Exceptions;

use DomainException;

/**
 * A supervisor override counted the shelf, and stock of the part was then recorded moving. The count
 * no longer describes the shelf, so adjusting to it could record stock that never existed: two
 * overrides adjusting one shelf twice, or a withdrawal in between that the count had already seen.
 * This is the staleness guard of design §5.6.1, applied to the override of §5.3. The remedy is a new
 * count and a new authorisation.
 */
final class StockMovedSinceCount extends DomainException
{
    /**
     * @param  list<string>  $partNumbers
     */
    public function __construct(public readonly array $partNumbers)
    {
        parent::__construct(
            'Stock of '.implode(', ', $partNumbers).' moved since the supervisor counted it. '
            .'Count the shelf again and authorise a new override.'
        );
    }
}
