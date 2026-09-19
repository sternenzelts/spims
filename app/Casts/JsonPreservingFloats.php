<?php

namespace App\Casts;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * jsonb, like Laravel's built-in "json" cast, except a float with no fractional part keeps its
 * decimal point (2.0, not 2) so it is read back as a float rather than an int. PHP's json_encode()
 * drops the fraction by default; JSON_PRESERVE_ZERO_FRACTION keeps it, and Postgres jsonb preserves
 * that textual form on the way back out.
 *
 * @implements CastsAttributes<mixed, mixed>
 */
final class JsonPreservingFloats implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return $value === null ? null : json_decode((string) $value, true);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): mixed
    {
        return $value === null ? null : json_encode($value, JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
