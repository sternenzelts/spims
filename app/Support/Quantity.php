<?php

namespace App\Support;

use Brick\Math\BigDecimal;

/**
 * Quantities for display: numeric(14,3) values without trailing zeros, so 5.000 reads 5 and 2.500
 * reads 2.5. Never used for arithmetic.
 */
final class Quantity
{
    public static function display(?string $quantity): string
    {
        return $quantity === null ? '' : (string) BigDecimal::of($quantity)->strippedOfTrailingZeros();
    }
}
