<?php

namespace App\Models;

use App\Casts\JsonPreservingFloats;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A saved install setting. Read settings through App\Domain\Settings\InstallSettings, which
 * supplies the default for every key that has no row here.
 *
 * @property string $key
 * @property mixed $value
 * @property int|null $updated_by_user_id
 * @property CarbonImmutable $updated_at
 */
#[Table(key: 'key', keyType: 'string', incrementing: false)]
#[Fillable(['key', 'value', 'updated_by_user_id'])]
class Setting extends Model
{
    public const CREATED_AT = null;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value' => JsonPreservingFloats::class,
        ];
    }
}
