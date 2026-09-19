<?php

namespace App\Support;

/**
 * A value from an audit entry's old_values or new_values, as a person reads it.
 */
final class AuditValue
{
    public static function display(mixed $value): string
    {
        return match (true) {
            $value === null => '—',
            is_bool($value) => $value ? 'Yes' : 'No',
            is_array($value) => implode(', ', array_map(self::display(...), $value)),
            default => (string) $value,
        };
    }
}
