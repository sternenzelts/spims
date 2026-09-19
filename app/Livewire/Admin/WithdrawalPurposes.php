<?php

namespace App\Livewire\Admin;

use App\Domain\Settings\Exceptions\PurposeChangeRefused;
use App\Domain\Settings\PurposeCatalogue;
use App\Enums\Permission;
use App\Livewire\Concerns\ActsAsSignedInUser;
use App\Models\WithdrawalPurpose;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Withdrawal purposes')]
class WithdrawalPurposes extends Component
{
    use ActsAsSignedInUser;

    /** The purpose open in the form; null while adding one. */
    #[Locked]
    public ?int $editingId = null;

    public string $name = '';

    public bool $requires_remarks = false;

    public bool $is_active = true;

    public function mount(): void
    {
        $this->authorize(Permission::SettingsManage);
    }

    /**
     * @return Collection<int, WithdrawalPurpose>
     */
    #[Computed]
    public function purposes(): Collection
    {
        return WithdrawalPurpose::query()->orderBy('sort_order')->orderBy('id')->get();
    }

    public function create(): void
    {
        $this->authorize(Permission::SettingsManage);

        $this->reset('editingId', 'name', 'requires_remarks', 'is_active');
        $this->resetErrorBag();

        Flux::modal('purpose-form')->show();
    }

    public function edit(int $purposeId): void
    {
        $this->authorize(Permission::SettingsManage);

        $purpose = WithdrawalPurpose::query()->findOrFail($purposeId);

        $this->editingId = $purpose->id;
        $this->name = $purpose->name;
        $this->requires_remarks = $purpose->requires_remarks;
        $this->is_active = $purpose->is_active;
        $this->resetErrorBag();

        Flux::modal('purpose-form')->show();
    }

    public function save(PurposeCatalogue $catalogue): void
    {
        $this->authorize(Permission::SettingsManage);

        $this->validate([
            'name' => ['required', 'string', 'max:100'],
            'requires_remarks' => ['boolean'],
            'is_active' => ['boolean'],
        ]);

        try {
            if ($this->editingId === null) {
                $catalogue->add($this->name, $this->requires_remarks, $this->actor());
            } else {
                $catalogue->update(
                    WithdrawalPurpose::query()->findOrFail($this->editingId),
                    $this->name,
                    $this->requires_remarks,
                    $this->is_active,
                    $this->actor(),
                );
            }
        } catch (PurposeChangeRefused $refused) {
            $this->addError($refused->field, $refused->getMessage());

            return;
        }

        Flux::modal('purpose-form')->close();
        Flux::toast(variant: 'success', text: __('Withdrawal purpose saved.'));
    }

    public function remove(int $purposeId, PurposeCatalogue $catalogue): void
    {
        $this->authorize(Permission::SettingsManage);

        try {
            $catalogue->remove(WithdrawalPurpose::query()->findOrFail($purposeId), $this->actor());
        } catch (PurposeChangeRefused $refused) {
            $this->addError('purposes', $refused->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Withdrawal purpose deleted.'));
    }
}
