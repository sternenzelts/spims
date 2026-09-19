<?php

namespace App\Domain\Identity;

use App\Domain\Audit\AuditLogger;
use App\Domain\Identity\Exceptions\DuplicateEmployeeNumber;
use App\Models\Personnel;
use App\Models\User;
use App\Support\Text;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The people who receive spare parts (PRD FR-SPIMS-004). They never sign in. A person who leaves is
 * set to Inactive: their badge stops confirming withdrawals and their history is kept. Badges are
 * enrolled through PersonnelBadges, never here.
 */
final readonly class PersonnelRegistry
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @throws DuplicateEmployeeNumber
     */
    public function register(string $employeeNo, string $fullName, ?string $department, ?string $photoPath, User $actor): Personnel
    {
        $this->guardUniqueEmployeeNumber($employeeNo, null);

        return DB::transaction(function () use ($employeeNo, $fullName, $department, $photoPath, $actor): Personnel {
            $personnel = Personnel::query()->create([
                'employee_no' => $employeeNo,
                'full_name' => trim($fullName),
                'department' => Text::nullIfBlank($department),
                'photo_path' => $photoPath,
                'is_active' => true,
            ]);

            $this->audit->record('created', $personnel, null, $this->snapshot($personnel), $actor);

            return $personnel;
        });
    }

    /**
     * @param  string|null  $newPhotoPath  null keeps the current photo
     *
     * @throws DuplicateEmployeeNumber
     */
    public function update(
        Personnel $personnel,
        string $employeeNo,
        string $fullName,
        ?string $department,
        ?string $newPhotoPath,
        bool $isActive,
        User $actor,
    ): void {
        $this->guardUniqueEmployeeNumber($employeeNo, $personnel);

        DB::transaction(function () use ($personnel, $employeeNo, $fullName, $department, $newPhotoPath, $isActive, $actor): void {
            $old = $this->snapshot($personnel);

            $personnel->forceFill([
                'employee_no' => $employeeNo,
                'full_name' => trim($fullName),
                'department' => Text::nullIfBlank($department),
                'photo_path' => $newPhotoPath ?? $personnel->photo_path,
                'is_active' => $isActive,
            ])->save();

            $new = $this->snapshot($personnel);

            $this->audit->recordChanges($personnel, $old, $new, $actor);
        });
    }

    /**
     * @throws DuplicateEmployeeNumber
     */
    private function guardUniqueEmployeeNumber(string $employeeNo, ?Personnel $except): void
    {
        $holder = Personnel::query()
            ->where('employee_no', Str::upper(trim($employeeNo)))
            ->when($except, fn ($query, Personnel $except) => $query->whereKeyNot($except->id))
            ->first();

        if ($holder !== null) {
            throw new DuplicateEmployeeNumber($holder);
        }
    }

    /**
     * @return array{employee_no: string, full_name: string, department: string|null, photo_path: string|null, is_active: bool}
     */
    private function snapshot(Personnel $personnel): array
    {
        return [
            'employee_no' => $personnel->employee_no,
            'full_name' => $personnel->full_name,
            'department' => $personnel->department,
            'photo_path' => $personnel->photo_path,
            'is_active' => $personnel->is_active,
        ];
    }
}
