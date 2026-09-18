<?php

namespace App\Domain\Identity;

use App\Domain\Identity\Exceptions\InvalidBadgeInput;

/**
 * The hardware boundary (design §10). Only the implementation changes if a serial or vendor-SDK
 * reader replaces the keyboard-wedge reader.
 */
interface BadgeReader
{
    /**
     * Turn raw reader input into the canonical badge UID.
     *
     * @throws InvalidBadgeInput
     */
    public function normalise(string $rawInput): string;
}
