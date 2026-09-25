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

    /**
     * Not a Livewire property: reset to false on every request rather than persisted, which is
     * exactly what's needed here. Livewire applies property updates — running updatedReceivedOn()
     * etc. — before invoking a requested action in the same request, so a mouse click that bundles
     * an invalid edit's blur with an immediate Confirm delivery click runs saveDelivery() first.
     * Without this flag, confirm()'s own resetErrorBag() would wipe that refusal and confirm the
     * delivery on the reverted-but-unremarked-on value. Set only by saveDelivery()'s catch, read
     * only here.
     */
    private bool $autoSaveRefused = false;

    public function mount(): void
    {
        $this->authorize(Permission::ReplenishmentsRecord);

        $draft = $this->draft();
        $this->supplier = (string) ($draft->supplier ?? '');
        $this->referenceNo = (string) ($draft->reference_no ?? '');
        // The draft's own occurred_at, not "today": a draft can be reopened after midnight
        // (NFR-SPIMS-007), and showing today here would silently re-date it the moment autosave
        // next runs (wave-1's carried note). A brand-new draft's occurred_at is its creation time,
        // which is today, so this is also correct on a first visit.
        $this->receivedOn = $this->occurredOnDate($draft);
    }

    public function saveDelivery(): void
    {
        $this->authorize(Permission::ReplenishmentsRecord);
        $this->resetErrorBag();

        $draft = $this->draft();

        try {
            app(ReplenishmentService::class)->setDelivery($draft, $this->supplier, $this->referenceNo, $this->receivedOn);
        } catch (ReplenishmentRefused $refused) {
            $this->addError('receivedOn', $refused->getMessage());
            // The three fields save together; none of them took, so the screen reverts to match
            // what the draft still holds (finding C1).
            $this->supplier = (string) ($draft->supplier ?? '');
            $this->referenceNo = (string) ($draft->reference_no ?? '');
            $this->receivedOn = $this->occurredOnDate($draft);
            // A Confirm click bundled into this same request (re-review item 1) must not proceed
            // on the strength of the value just reverted above.
            $this->autoSaveRefused = true;

            return;
        }

        $this->forget();
    }

    /**
     * The supplier and reference save on blur (see the Blade's wire:model.blur), never on a
     * separate Save click, so the draft can never disagree with what the screen shows (finding
     * C1). setDelivery() refuses unless both are present together, so autosave waits for both
     * rather than showing "reference required" the moment the supplier field alone is blurred and
     * reverting what was just typed there.
     */
    public function updatedSupplier(): void
    {
        $this->autoSaveDelivery();
    }

    public function updatedReferenceNo(): void
    {
        $this->autoSaveDelivery();
    }

    public function updatedReceivedOn(): void
    {
        $this->autoSaveDelivery();
    }

    private function autoSaveDelivery(): void
    {
        // Re-review item 1 (Minor): both early returns below skip saveDelivery() entirely, so
        // without this, resending an unchanged (or still-incomplete) value would authorize nothing
        // at all — the one interaction on this screen that wouldn't 403 a revoked custodian.
        $this->authorize(Permission::ReplenishmentsRecord);

        if (trim($this->supplier) === '' || trim($this->referenceNo) === '') {
            return;
        }

        $draft = $this->draft();

        $unchanged = trim($this->supplier) === (string) ($draft->supplier ?? '')
            && Str::upper(trim($this->referenceNo)) === (string) ($draft->reference_no ?? '')
            && $this->receivedOn === $this->occurredOnDate($draft);

        if ($unchanged) {
            return;
        }

        $this->saveDelivery();
    }

    private function occurredOnDate(StockTransaction $draft): string
    {
        return $draft->occurred_at->setTimezone(app(InstallSettings::class)->timezone())->toDateString();
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
            // Custodians can't reach /parts/create (Ruling C), so don't point them at a dead end.
            $this->addError('partEntry', $this->actor()->can('create', Part::class)
                ? __('No spare part has that number. Pick one from the matches below, or create it on the spare parts screen.')
                : __('No spare part has that number. Pick one from the matches below.'));
            $this->dispatch('station-focus', field: 'partEntry');

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
            $this->dispatch('station-focus', field: 'partEntry');

            return;
        }

        $this->partEntry = '';
        $this->quantity = '1';
        $this->unitCost = '';
        $this->forget();
        $this->dispatch('station-focus', field: 'partEntry');
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

        // A property update bundled into this same request (a mouse click fires the field it
        // leaves' blur before the click itself, and Livewire sends both together) was refused by
        // saveDelivery() above. resetErrorBag() below would wipe that refusal, and completing the
        // delivery now would use the value saveDelivery() already reverted away from — silently
        // confirming on stale context with no error shown (re-review item 1). Leave it exactly as
        // saveDelivery() left it and do nothing else.
        if ($this->autoSaveRefused) {
            return;
        }

        $this->resetErrorBag();

        $draft = $this->draft();

        // Autosave waits for both supplier and reference to be non-empty (above), so clearing a
        // saved one back to blank and confirming without ever refilling it would otherwise post
        // whatever the draft last held while the screen showed something else entirely.
        if (trim($this->supplier) !== (string) ($draft->supplier ?? '')
            || Str::upper(trim($this->referenceNo)) !== (string) ($draft->reference_no ?? '')) {
            $this->addError('draft', __('The supplier or reference on screen has not been saved. Fill both in before confirming.'));
            $this->dispatch('station-focus', field: 'supplier');

            return;
        }

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
