<?php

namespace App\Domain\Ledger;

final readonly class Shortage
{
    public function __construct(
        public string $partNumber,
        public string $available,
        public string $requested,
    ) {}
}
