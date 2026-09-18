<?php

namespace App\Domain\Identity;

use App\Domain\Identity\Exceptions\InvalidBadgeInput;

/**
 * An HID keyboard-wedge reader "types" the card UID into a focused field and presses Enter.
 * Input arrives with stray whitespace, control characters and, on some models, separators.
 */
final class KeyboardWedgeBadgeReader implements BadgeReader
{
    public function normalise(string $rawInput): string
    {
        $uid = strtoupper((string) preg_replace('/[\s:\-\x00-\x1F\x7F]+/', '', $rawInput));

        if ($uid === '') {
            throw InvalidBadgeInput::empty();
        }

        if (preg_match('/^[A-Z0-9]{4,64}$/', $uid) !== 1) {
            throw InvalidBadgeInput::malformed();
        }

        return $uid;
    }
}
