<?php

namespace App\Livewire\Station;

use App\Domain\Ledger\Exceptions\InstallNotConfigured;
use App\Domain\Ledger\Exceptions\StockBusy;
use App\Domain\Ledger\StockLedger;
use App\Domain\Parts\PartSearch;
use App\Domain\Replenishments\Exceptions\ReplenishmentRefused;
use App\Domain\Replenishments\ReplenishmentService;
use App\Domain\Settings\InstallSettings;
use App\Enums\Permission;
use App\Livewire\Concerns\ActsAsSignedInUser;
use App\Models\Part;
use App\Models\StockTransaction;
use App\Models\StockTransactionLine;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Spare parts received into the storeroom (PRD FR-SPIMS-009, design §5.4). The withdrawal station
 * without the badge, the override or the personnel: what carries the accountability here is the
 * supplier and the delivery reference.
 */
#[Title('Record a delivery')]
class Replenishment extends Component
{
    use ActsAsSignedInUser;

    public string $supplier = '';

    public string $referenceNo = '';

    public string $receivedOn = '';

    public string $partEntry = '';

    public string $quantity = '1';

    public string $unitCost = '';

    public function mount(): void
    {
        $this->authorize(Permission::ReplenishmentsRecord);

        $draft = $this->draft();
        $this->supplier = (string) ($draft->supplier ?? '');
        $this->referenceNo = (string) ($draft->reference_no ?? '');
        $this->receivedOn = CarbonImmutable::now()
            ->setTimezone(app(InstallSettings::class)->timezone())
            ->toDateString();
    }

    public function saveDelivery(): void
    {
        $this->authorize(Permission::ReplenishmentsRecord);
        $this->resetErrorBag();

        try {
            app(ReplenishmentService::class)->setDelivery($this->draft(), $this->supplier, $this->referenceNo, $this->receivedOn);
        } catch (ReplenishmentRefused $refused) {
            $this->addError('receivedOn', $refused->getMessage());

            return;
        }

        $this->forget();
    }

    public function enterPart(): void
    {
        $this->authorize(Permission::ReplenishmentsRecord);
        $this->resetErrorBag();

        $entry = trim($this->partEntry);

        if ($entry === '') {
            return;
        }

        $part = Part::query()->whereRaw('lower(part_number) = ?', [Str::lower($entry)])->first();

        if (! $part instanceof Part) {
            $this->addError('partEntry', __('No spare part has that number. Pick one from the matches below, or create it on the spare parts screen.'));

            return;
        }

        $this->addPart($part->id);
    }

    public function addPart(int $partId): void
    {
        $this->authorize(Permission::ReplenishmentsRecord);
        $this->resetErrorBag();

        try {
            app(ReplenishmentService::class)->addLine(
                $this->draft(),
                Part::query()->findOrFail($partId),
                $this->quantity,
                $this->unitCost === '' ? null : $this->unitCost,
            );
        } catch (ReplenishmentRefused $refused) {
            $this->addError('partEntry', $refused->getMessage());

            return;
        }

        $this->partEntry = '';
        $this->quantity = '1';
        $this->unitCost = '';
        $this->forget();
    }

    public function removeLine(int $lineId): void
    {
        $this->authorize(Permission::ReplenishmentsRecord);

        $draft = $this->draft();
        $line = $draft->lines()->whereKey($lineId)->first();

        if ($line instanceof StockTransactionLine) {
            app(ReplenishmentService::class)->removeLine($draft, $line);
        }

        $this->forget();
    }

    public function confirm(): void
    {
        $this->authorize(Permission::ReplenishmentsRecord);
        $this->resetErrorBag();

        $draft = $this->draft();

        try {
            app(ReplenishmentService::class)->guardReadyToConfirm($draft);
            app(StockLedger::class)->complete($draft, $this->actor());
        } catch (ReplenishmentRefused|InstallNotConfigured $refused) {
            $this->addError('draft', $refused->getMessage());

            return;
        } catch (StockBusy $busy) {
            $this->addError('draft', $busy->getMessage());

            return;
        }

        $this->reset(['supplier', 'referenceNo', 'partEntry', 'quantity', 'unitCost']);
        $this->forget();

        Flux::toast(variant: 'success', text: __('Delivery recorded and stock updated.'));
    }

    public function cancelDraft(): void
    {
        $this->authorize(Permission::ReplenishmentsRecord);

        app(ReplenishmentService::class)->cancel($this->draft(), $this->actor());

        $this->reset(['supplier', 'referenceNo', 'partEntry', 'quantity', 'unitCost']);
        $this->forget();
    }

    #[Computed]
    public function draft(): StockTransaction
    {
        return app(ReplenishmentService::class)->openDraft($this->actor());
    }

    #[Computed]
    public function duplicate(): ?StockTransaction
    {
        return app(ReplenishmentService::class)->duplicateDelivery($this->draft());
    }

    /**
     * @return list<Part>
     */
    #[Computed]
    public function overMaximum(): array
    {
        return app(ReplenishmentService::class)->overMaximum($this->draft());
    }

    /**
     * @return Collection<int, Part>
     */
    #[Computed]
    public function matches(): Collection
    {
        if (trim($this->partEntry) === '') {
            return new Collection;
        }

        return app(PartSearch::class)->matching($this->partEntry)
            ->where('is_active', true)
            ->with('stock')
            ->limit(8)
            ->get();
    }

    private function forget(): void
    {
        unset($this->draft, $this->duplicate, $this->overMaximum, $this->matches);
    }
}
