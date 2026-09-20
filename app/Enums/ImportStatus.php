<?php

namespace App\Enums;

/**
 * PRD §7.7: an import batch is Pending until checked, Validated when every row passed, Failed when
 * any did, Completed once imported, or Cancelled.
 */
enum ImportStatus: string
{
    case Pending = 'pending';
    case Validated = 'validated';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for a check',
            self::Validated => 'Ready to import',
            self::Completed => 'Imported',
            self::Failed => 'Needs fixing',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Whether the batch can still be checked, imported or cancelled.
     */
    public function isOpen(): bool
    {
        return in_array($this, [self::Pending, self::Validated, self::Failed], true);
    }
}
