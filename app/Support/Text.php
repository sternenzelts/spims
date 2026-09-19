<?php

namespace App\Support;

/**
 * Free text typed into a form.
 */
final class Text
{
    /**
     * Trimmed, or null when nothing but whitespace was typed.
     */
    public static function nullIfBlank(?string $text): ?string
    {
        $text = trim((string) $text);

        return $text === '' ? null : $text;
    }
}
