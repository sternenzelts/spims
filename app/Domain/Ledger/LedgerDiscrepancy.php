<?php

namespace App\Domain\Ledger;

final readonly class LedgerDiscrepancy
{
    public function __construct(
        public DiscrepancyKind $kind,
        public int $partId,
        public string $partNumber,
        public string $expected,
        public string $actual,
        public ?int $movementId = null,
    ) {}
}
