<?php

namespace App\Livewire\Personnel;

use App\Domain\Identity\Exceptions\BadgeAlreadyEnrolled;
use App\Domain\Identity\Exceptions\DuplicateEmployeeNumber;
use App\Domain\Identity\Exceptions\InvalidBadgeInput;
use App\Domain\Identity\PersonnelBadges;
use App\Domain\Identity\PersonnelRegistry;
use App\Enums\Permission;
use App\Livewire\Concerns\ActsAsSignedInUser;
use App\Models\Personnel;
use Flux\Flux;
use Illuminate\Database\UniqueConstraintViolationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use RuntimeException;

#[Title('Personnel')]
class Form extends Component
{
    use ActsAsSignedInUser, WithFileUploads;

    /** The person being edited; null while registering one. */
    #[Locked]
    public ?int $personnelId = null;

    public string $employee_no = '';

    public string $full_name = '';

    public string $department = '';

    /** @var TemporaryUploadedFile|null */
    public $photo = null;

    public bool $is_active = true;

    /** What the badge reader typed into the scan field. */
    public string $badgeScan = '';

    /** The full badge identifier, once a System Administrator asks for it. */
    #[Locked]
    public ?string $revealedBadge = null;

    public function mount(?Personnel $personnel = null): void
    {
        $this->authorize(Permission::PersonnelManage);

        if ($personnel?->exists) {
            $this->personnelId = $personnel->id;
            $this->employee_no = $personnel->employee_no;
            $this->full_name = $personnel->full_name;
            $this->department = (string) $personnel->department;
            $this->is_active = $personnel->is_active;
        }
    }

    public function save(PersonnelRegistry $registry): void
    {
        $this->authorize(Permission::PersonnelManage);

        $this->validate([
            'employee_no' => ['required', 'string', 'max:50'],
            'full_name' => ['required', 'string', 'max:255'],
            'department' => ['nullable', 'string', 'max:255'],
            'photo' => ['nullable', 'file', 'mimes:jpg,jpeg,png,webp', 'max:4096'],
            'is_active' => ['boolean'],
        ]);

        $photoPath = null;

        if ($this->photo !== null) {
            $photoPath = $this->photo->store('personnel') ?: throw new RuntimeException('The photo could not be stored.');
        }

        try {
            if ($this->personnelId === null) {
                $personnel = $registry->register($this->employee_no, $this->full_name, $this->department, $photoPath, $this->actor());

                Flux::toast(variant: 'success', text: __('Registered. Scan the badge to enroll it.'));
                $this->redirectRoute('personnel.edit', ['personnel' => $personnel], navigate: true);

                return;
            }

            $registry->update(
                Personnel::query()->findOrFail($this->personnelId),
                $this->employee_no,
                $this->full_name,
                $this->department,
                $photoPath,
                $this->is_active,
                $this->actor(),
            );
        } catch (DuplicateEmployeeNumber $duplicate) {
            $this->addError('employee_no', $duplicate->getMessage());

            return;
        }

        $this->photo = null;

        Flux::toast(variant: 'success', text: __('Personnel saved.'));
    }

    /**
     * The reader types the badge UID into the scan field and presses Enter (design §10).
     */
    public function enrollBadge(PersonnelBadges $badges): void
    {
        $this->authorize(Permission::PersonnelManage);

        $personnel = Personnel::query()->findOrFail($this->personnelId);

        try {
            $badges->enroll($personnel, $this->badgeScan, $this->actor());
        } catch (InvalidBadgeInput|BadgeAlreadyEnrolled $refused) {
            $this->addError('badgeScan', $refused->getMessage());

            return;
        } catch (UniqueConstraintViolationException) {
            // Enrolled to someone else at the same moment (Plan 1 Task 6 review).
            $this->addError('badgeScan', __('This badge is already enrolled to another person. Scan it again to see who holds it.'));

            return;
        } finally {
            $this->badgeScan = '';
        }

        $this->revealedBadge = null;

        Flux::toast(variant: 'success', text: __('Badge enrolled.'));
    }

    public function revealBadge(): void
    {
        $this->authorize(Permission::BadgesViewFull);

        $this->revealedBadge = Personnel::query()->findOrFail($this->personnelId)->badge_uid_encrypted;
    }

    #[Computed]
    public function badgeMasked(): ?string
    {
        return $this->personnelId === null ? null : Personnel::query()->findOrFail($this->personnelId)->badge_uid_masked;
    }

    #[Computed]
    public function photoUrl(): ?string
    {
        $path = $this->personnelId === null ? null : Personnel::query()->findOrFail($this->personnelId)->photo_path;

        return $path === null ? null : route('media.show', ['path' => $path]);
    }
}
