<?php

namespace App\Support;

/**
 * LIKE patterns built from what a user typed. LIKE's own wildcards in the term are escaped, so a
 * search for "50%" or "A_1" matches those characters literally (Postgres escapes with a backslash).
 */
final class SearchTerm
{
    public static function contains(string $term): string
    {
        return '%'.self::escape(trim($term)).'%';
    }

    public static function startsWith(string $term): string
    {
        return self::escape(trim($term)).'%';
    }

    private static function escape(string $term): string
    {
        return addcslashes($term, '\\%_');
    }
}
