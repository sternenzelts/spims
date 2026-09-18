<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\PersonnelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A person who receives spare parts. Never signs in (design §3.5). The badge columns are written
 * only by App\Domain\Identity\PersonnelBadges.
 *
 * @property int $id
 * @property string $employee_no
 * @property string $full_name
 * @property string|null $department
 * @property string|null $badge_uid_hash
 * @property string|null $badge_uid_encrypted
 * @property string|null $badge_uid_masked
 * @property string|null $photo_path
 * @property bool $is_active
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 */
#[Table(name: 'personnel')]
#[Fillable(['employee_no', 'full_name', 'department', 'photo_path', 'is_active'])]
#[Hidden(['badge_uid_hash', 'badge_uid_encrypted'])]
class Personnel extends Model
{
    /** @use HasFactory<PersonnelFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'badge_uid_encrypted' => 'encrypted',
            'is_active' => 'boolean',
        ];
    }
}
