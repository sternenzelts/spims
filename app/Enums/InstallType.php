<?php

namespace App\Enums;

use Illuminate\Support\Facades\Config;
use UnexpectedValueException;

enum InstallType: string
{
    case Production = 'production';
    case Demo = 'demo';

    /**
     * The type this install was provisioned as, from APP_INSTALL_TYPE (design §3.6 rule 5).
     */
    public static function current(): self
    {
        $value = Config::string('spims.install_type');

        return self::tryFrom($value)
            ?? throw new UnexpectedValueException("APP_INSTALL_TYPE must be demo or production, got [{$value}].");
    }

    public function label(): string
    {
        return match ($this) {
            self::Production => 'Production install',
            self::Demo => 'Demo install',
        };
    }
}
