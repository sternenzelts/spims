<?php

namespace App\Livewire\Imports;

use App\Domain\Imports\Exceptions\ImportRefused;
use App\Domain\Imports\ImportColumn;
use App\Domain\Imports\PartImports;
use App\Enums\Permission;
use App\Livewire\Concerns\ActsAsSignedInUser;
use App\Models\ImportBatch;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * PRD FR-SPIMS-005 steps 3–6 for one uploaded file: match its columns, check every row, read the
 * preview or the errors, then import or cancel.
 */
#[Title('Spare part import')]
class Review extends Component
{
    use ActsAsSignedInUser;

    #[Locked]
    public int $batchId;

    /** @var array<string, string> import column value => file column index, or '' when not in the file */
    public array $columnMap = [];

    public function mount(ImportBatch $batch): void
    {
        $this->authorize(Permission::PartsImport);

        $this->batchId = $batch->id;

        foreach (ImportColumn::cases() as $column) {
            $this->columnMap[$column->value] = (string) ($batch->column_map[$column->value] ?? '');
        }
    }

    public function check(PartImports $imports): void
    {
        $this->authorize(Permission::PartsImport);
        $this->resetErrorBag();

        $map = array_map(fn (mixed $index): ?int => is_numeric($index) ? (int) $index : null, $this->columnMap);

        try {
            $preview = $imports->check($this->batch(), $map, $this->actor());
        } catch (ImportRefused $refused) {
            $this->addError($refused->field === 'mapping' ? 'columnMap' : 'batch', $refused->getMessage());

            return;
        }

        Flux::toast(
            variant: $preview->isValid() ? 'success' : 'danger',
            text: $preview->isValid() ? __('Every row passed. Review the preview, then import.') : __('Some rows have errors. Nothing can be imported until they are fixed.'),
        );
    }

    public function import(PartImports $imports): void
    {
        $this->authorize(Permission::PartsImport);

        try {
            $imports->requestConfirmation($this->batch(), $this->actor());
        } catch (ImportRefused $refused) {
            $this->addError('batch', $refused->getMessage());
        }
    }

    public function cancel(PartImports $imports): void
    {
        $this->authorize(Permission::PartsImport);

        try {
            $imports->cancel($this->batch(), $this->actor());
        } catch (ImportRefused $refused) {
            $this->addError('batch', $refused->getMessage());

            return;
        }

        Flux::toast(variant: 'success', text: __('Import cancelled. Nothing was saved.'));
        $this->redirectRoute('imports.index', navigate: true);
    }

    /**
     * Read fresh on every request, so the screen shows what the import job has done.
     */
    #[Computed]
    public function batch(): ImportBatch
    {
        return ImportBatch::query()->with('importedBy')->findOrFail($this->batchId);
    }

    /**
     * The import columns to match, with whether each is required and whether this user may fill it.
     *
     * @return list<array{value: string, label: string, required: bool, permitted: bool}>
     */
    #[Computed]
    public function columns(): array
    {
        $actor = $this->actor();

        return array_map(fn (ImportColumn $column): array => [
            'value' => $column->value,
            'label' => $column->label(),
            'required' => $column->isRequired(),
            'permitted' => $column->permission() === null || $actor->can($column->permission()),
        ], ImportColumn::cases());
    }
}
