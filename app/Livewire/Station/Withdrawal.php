<?php

namespace App\Livewire\Station;

use App\Domain\Access\CounterAuthorisation;
use App\Domain\Access\Exceptions\AuthorisationRefused;
use App\Domain\Ledger\Exceptions\InstallNotConfigured;
use App\Domain\Ledger\Exceptions\InsufficientStock;
use App\Domain\Ledger\Exceptions\StockBusy;
use App\Domain\Ledger\Exceptions\StockMovedSinceCount;
use App\Domain\Ledger\Shortage;
use App\Domain\Ledger\StockLedger;
use App\Domain\Parts\PartSearch;
use App\Domain\Withdrawals\Exceptions\WithdrawalRefused;
use App\Domain\Withdrawals\WithdrawalIdentification;
use App\Domain\Withdrawals\WithdrawalReview;
use App\Domain\Withdrawals\WithdrawalService;
use App\Enums\Permission;
use App\Enums\ReasonCode;
use App\Livewire\Concerns\ActsAsSignedInUser;
use App\Models\Equipment;
use App\Models\Part;
use App\Models\Personnel;
use App\Models\StockTransaction;
use App\Models\StockTransactionLine;
use App\Models\WithdrawalPurpose;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The withdrawal station (design §5.1, PRD FR-SPIMS-006 to 008). Keyboard first: the part entry and
 * the badge scan both submit on Enter, which is what a keyboard-wedge reader sends.
 *
 * No rule is implemented here. Every refusal comes from a domain exception and is shown as written,
 * because these messages are read out loud at a counter.
 */
#[Title('Withdrawal station')]
class Withdrawal extends Component
{
    use ActsAsSignedInUser;

    public string $partEntry = '';

    public string $quantity = '1';

    public string $equipmentId = '';

    public string $purposeId = '';

    public string $remarks = '';

    public string $badge = '';

    /** Set only by requestOverride(): a crafted request must not retarget what the supervisor counts. */
    #[Locked]
    public int $overridePartId = 0;

    public string $verifiedQty = '';

    public string $overrideReason = '';

    public string $manualPersonnelId = '';

    public string $manualReason = '';

    /** Shared by the override and manual-identification dialogs. Never persisted. */
    public string $supervisorEmail = '';

    public string $supervisorPassword = '';

    public function mount(): void
    {
        $this->authorize(Permission::WithdrawalsProcess);

        $draft = $this->draft();
        $this->equipmentId = (string) ($draft->equipment_id ?? '');
        $this->purposeId = (string) ($draft->purpose_id ?? '');
        $this->remarks = (string) ($draft->remarks ?? '');
        $this->overrideReason = ReasonCode::FoundStock->value;
    }

    /**
     * Step 2 of design §5.1. An exact part number is added straight away; anything else leaves the
     * entry in place and the matches below it (FR-SPIMS-006 alternate flow).
     */
    public function enterPart(): void
    {
        $this->authorize(Permission::WithdrawalsProcess);
        $this->resetErrorBag();

        $entry = trim($this->partEntry);

        if ($entry === '') {
            return;
        }

        $part = Part::query()->whereRaw('lower(part_number) = ?', [Str::lower($entry)])->first();

        if (! $part instanceof Part) {
            $this->addError('partEntry', __('No spare part has that number. Pick one from the matches below.'));
            $this->dispatch('station-focus', field: 'partEntry');

            return;
        }

        $this->addPart($part->id);
    }

    public function addPart(int $partId): void
    {
        $this->authorize(Permission::WithdrawalsProcess);
        $this->resetErrorBag();

        try {
            app(WithdrawalService::class)->addLine($this->draft(), Part::query()->findOrFail($partId), $this->quantity);
        } catch (WithdrawalRefused $refused) {
            $this->addError('partEntry', $refused->getMessage());
            $this->dispatch('station-focus', field: 'partEntry');

            return;
        }

        $this->partEntry = '';
        $this->quantity = '1';
        $this->forget();
        // Whether typed or picked from the matches below, the part field is where the next scan
        // or the next part goes (walk W1).
        $this->dispatch('station-focus', field: 'partEntry');
    }

    public function removeLine(int $lineId): void
    {
        $this->authorize(Permission::WithdrawalsProcess);
        $this->resetErrorBag();

        $draft = $this->draft();
        $line = $draft->lines()->whereKey($lineId)->first();

        if ($line instanceof StockTransactionLine) {
            app(WithdrawalService::class)->removeLine($draft, $line);
        }

        $this->forget();
    }

    public function saveContext(): void
    {
        $this->authorize(Permission::WithdrawalsProcess);
        $this->resetErrorBag();

        try {
            app(WithdrawalService::class)->setContext(
                $this->draft(),
                $this->equipmentId === '' ? null : Equipment::query()->findOrFail((int) $this->equipmentId),
                $this->purposeId === '' ? null : WithdrawalPurpose::query()->findOrFail((int) $this->purposeId),
                $this->remarks,
            );
        } catch (WithdrawalRefused $refused) {
            $this->addError('equipmentId', $refused->getMessage());
            $this->dispatch('station-focus', field: 'equipmentId');

            return;
        }

        $this->forget();
    }

    /**
     * Step 5 of design §5.1: the reader types the UID and presses Enter.
     */
    public function scan(): void
    {
        $this->authorize(Permission::WithdrawalsProcess);
        $this->resetErrorBag();

        try {
            app(WithdrawalIdentification::class)->identify($this->draft(), $this->badge, $this->actor());
        } catch (WithdrawalRefused $refused) {
            $this->addError('badge', $refused->getMessage());
            $this->dispatch('station-focus', field: 'badge');

            return;
        } finally {
            $this->badge = '';
        }

        $this->forget();
        $this->dispatch('station-focus', field: 'confirmWithdrawal');
    }

    /**
     * FR-SPIMS-007 alternate flow: the person at the counter is not the one on screen.
     */
    public function clearIdentification(): void
    {
        $this->authorize(Permission::WithdrawalsProcess);

        app(WithdrawalIdentification::class)->clear($this->draft());

        $this->forget();
        $this->dispatch('station-focus', field: 'badge');
    }

    public function requestOverride(int $partId): void
    {
        $this->authorize(Permission::WithdrawalsProcess);
        $this->resetErrorBag();

        $this->overridePartId = $partId;
        $this->verifiedQty = '';
        $this->overrideReason = ReasonCode::FoundStock->value;
        $this->supervisorEmail = '';
        $this->supervisorPassword = '';

        Flux::modal('supervisor-override')->show();
    }

    /**
     * FR-SPIMS-008 steps 2 to 5. The supervisor's credentials identify them; they never sign in,
     * and the station stays the custodian's.
     */
    public function authoriseOverride(): void
    {
        $this->authorize(Permission::WithdrawalsProcess);
        $this->resetErrorBag();

        try {
            $supervisor = app(CounterAuthorisation::class)->verify(
                $this->supervisorEmail,
                $this->supervisorPassword,
                Permission::OverridesAuthorise,
                (string) request()->ip(),
            );

            app(WithdrawalService::class)->authoriseOverride(
                $this->draft(),
                Part::query()->findOrFail($this->overridePartId),
                $this->verifiedQty,
                ReasonCode::from($this->overrideReason),
                $supervisor,
            );
        } catch (AuthorisationRefused $refused) {
            $this->addError('supervisorPassword', $refused->getMessage());
            $this->dispatch('station-focus', field: 'supervisorPassword');

            return;
        } catch (WithdrawalRefused $refused) {
            $this->addError('verifiedQty', $refused->getMessage());
            $this->dispatch('station-focus', field: 'verifiedQty');

            return;
        } finally {
            $this->supervisorPassword = '';
        }

        Flux::modal('supervisor-override')->close();
        Flux::toast(variant: 'success', text: __('Override authorised. The adjustment is recorded when you confirm.'));
        $this->forget();
        // Authorising an override always clears the identification (it changes the cart's stock
        // story), so this lands on the scan field in practice — checked, not assumed, because the
        // same helper also serves identifyManually(), where the person is now identified.
        $this->focusAfterIdentificationChange();
    }

    /**
     * Opens "No badge?" empty, as requestOverride() opens its dialog. The two dialogs share the
     * supervisor fields, so a password typed into a dismissed dialog must never be submitted by the
     * other one in the name of a supervisor who approved nothing.
     */
    public function requestManualIdentification(): void
    {
        $this->authorize(Permission::WithdrawalsProcess);
        $this->resetErrorBag();

        $this->manualPersonnelId = '';
        $this->manualReason = '';
        $this->supervisorEmail = '';
        $this->supervisorPassword = '';

        Flux::modal('manual-identification')->show();
    }

    /**
     * FR-SPIMS-007 alternate flow, pending Microtech's answer to Q12.
     */
    public function identifyManually(): void
    {
        $this->authorize(Permission::WithdrawalsProcess);
        $this->resetErrorBag();

        try {
            $supervisor = app(CounterAuthorisation::class)->verify(
                $this->supervisorEmail,
                $this->supervisorPassword,
                Permission::IdentificationAuthorise,
                (string) request()->ip(),
            );

            app(WithdrawalIdentification::class)->identifyManually(
                $this->draft(),
                Personnel::query()->findOrFail((int) $this->manualPersonnelId),
                $supervisor,
                $this->manualReason,
            );
        } catch (AuthorisationRefused $refused) {
            $this->addError('supervisorPassword', $refused->getMessage());
            $this->dispatch('station-focus', field: 'manualSupervisorPassword');

            return;
        } catch (WithdrawalRefused $refused) {
            $this->addError('manualReason', $refused->getMessage());
            $this->dispatch('station-focus', field: 'manualReason');

            return;
        } finally {
            $this->supervisorPassword = '';
        }

        Flux::modal('manual-identification')->close();
        $this->forget();
        $this->focusAfterIdentificationChange();
    }

    /**
     * Steps 6 to 8 of design §5.1, in one short database transaction.
     */
    public function confirm(): void
    {
        $this->authorize(Permission::WithdrawalsProcess);
        $this->resetErrorBag();

        $draft = $this->draft();

        try {
            app(WithdrawalService::class)->guardReadyToConfirm($draft);
            $completed = app(StockLedger::class)->completeWithOverrides($draft, $this->actor());
        } catch (WithdrawalRefused|InstallNotConfigured $refused) {
            $this->addError('draft', $refused->getMessage());

            // FR-SPIMS-007 alternate flow: an expired identification is cleared, not just
            // reported, so the scan field is back on screen beside the message instead of the
            // stale callout staying up until "Not this person" is pressed.
            if ($draft->identified_at !== null && ! app(WithdrawalIdentification::class)->isCurrent($draft)) {
                try {
                    app(WithdrawalService::class)->invalidate($draft);
                } catch (WithdrawalRefused) {
                    // Another request already moved the draft past Draft; nothing left to clear.
                }

                $this->forget();
                $this->dispatch('station-focus', field: 'badge');

                return;
            }

            $this->dispatch('station-focus', field: $this->focusForConfirmRefusal($draft));

            return;
        } catch (InsufficientStock $short) {
            $this->addError('draft', __('Stock changed while you were reviewing. Short: :parts', [
                'parts' => implode(', ', array_map(
                    fn (Shortage $shortage): string => $shortage->partNumber,
                    $short->shortages,
                )),
            ]));
            $this->forget();
            $this->dispatch('station-focus', field: 'confirmWithdrawal');

            return;
        } catch (StockMovedSinceCount $stale) {
            $this->addError('draft', $stale->getMessage());
            $this->forget();
            $this->dispatch('station-focus', field: 'confirmWithdrawal');

            return;
        } catch (StockBusy $busy) {
            $this->addError('draft', $busy->getMessage());
            $this->dispatch('station-focus', field: 'confirmWithdrawal');

            return;
        }

        $this->redirectRoute('station.receipt', ['transaction' => $completed->id], navigate: true);
    }

    /**
     * Flux's wire:close fires whenever either dialog closes, by any route — submit, Escape, the
     * backdrop or the close button — so a password typed into a dismissed dialog never survives on
     * the server (review B, wave-1 leftover). The two dialogs share these fields (never persisted).
     */
    public function forgetSupervisorCredentials(): void
    {
        $this->authorize(Permission::WithdrawalsProcess);

        $this->supervisorEmail = '';
        $this->supervisorPassword = '';
    }

    public function cancelDraft(): void
    {
        $this->authorize(Permission::WithdrawalsProcess);

        app(WithdrawalService::class)->cancel($this->draft(), $this->actor());

        $this->reset(['partEntry', 'quantity', 'equipmentId', 'purposeId', 'remarks', 'badge', 'supervisorEmail', 'supervisorPassword']);
        $this->forget();

        Flux::toast(variant: 'success', text: __('Withdrawal cancelled. Nothing was recorded.'));
    }

    #[Computed]
    public function draft(): StockTransaction
    {
        return app(WithdrawalService::class)->openDraft($this->actor());
    }

    #[Computed]
    public function review(): WithdrawalReview
    {
        return app(WithdrawalService::class)->review($this->draft());
    }

    /**
     * Whether the identified-person callout should show (finding C3, walk W3): personnel_id alone
     * is not enough, because nothing clears it merely because the time limit passed. An expired
     * identification renders the scan field again on its own, without waiting for a failed confirm
     * or a click on "Not this person".
     */
    #[Computed]
    public function identifiedAndCurrent(): bool
    {
        $draft = $this->draft();

        return $draft->personnel_id !== null && app(WithdrawalIdentification::class)->isCurrent($draft);
    }

    /**
     * The part the override dialog is authorising, named in the dialog so the supervisor sees what
     * they are counting.
     */
    #[Computed]
    public function overridePart(): ?Part
    {
        return Part::query()->find($this->overridePartId);
    }

    /**
     * The spare parts an entry that is not a part number matches (FR-SPIMS-006 alternate flow).
     *
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
            ->with(['stock', 'defaultLocation'])
            ->limit(8)
            ->get();
    }

    /**
     * @return Collection<int, Equipment>
     */
    #[Computed]
    public function equipmentOptions(): Collection
    {
        return Equipment::query()->where('is_active', true)->orderBy('code')->get();
    }

    /**
     * @return Collection<int, WithdrawalPurpose>
     */
    #[Computed]
    public function purposeOptions(): Collection
    {
        return WithdrawalPurpose::query()->where('is_active', true)->orderBy('sort_order')->get();
    }

    /**
     * @return Collection<int, Personnel>
     */
    #[Computed]
    public function personnelOptions(): Collection
    {
        return Personnel::query()->where('is_active', true)->orderBy('full_name')->get();
    }

    /**
     * FR-SPIMS-007: after an override is authorised or a manual identification succeeds, focus
     * goes to Confirm if the person is still identified, or the scan field if that same change
     * just cleared it — authorising an override always does (WithdrawalService::authoriseOverride()
     * calls invalidate()); a manual identification instead just wrote one. Checked on the draft's
     * current state rather than assumed from which action ran, so this stays correct either way.
     */
    private function focusAfterIdentificationChange(): void
    {
        $draft = $this->draft();

        $current = $draft->personnel_id !== null && app(WithdrawalIdentification::class)->isCurrent($draft);

        $this->dispatch('station-focus', field: $current ? 'confirmWithdrawal' : 'badge');
    }

    /**
     * confirm()'s own checks, in guardReadyToConfirm()'s order, re-derived from the draft's current
     * state rather than from WithdrawalRefused (which carries only a message, not a reason), so the
     * field a refusal is about gets focus without a domain change.
     */
    private function focusForConfirmRefusal(StockTransaction $draft): string
    {
        if ($draft->lines()->count() === 0) {
            return 'partEntry';
        }

        if ($draft->equipment_id === null) {
            return 'equipmentId';
        }

        $purpose = $draft->purpose;

        if (! $purpose instanceof WithdrawalPurpose) {
            return 'purposeId';
        }

        if ($purpose->requires_remarks && trim((string) $draft->remarks) === '') {
            return 'remarks';
        }

        // Not identified at all (an expired identification is handled separately, above, because
        // it also clears the stale identification rather than just refusing on it).
        return 'badge';
    }

    /**
     * Drop the cached draft and review, so the next render reads what was just written.
     */
    private function forget(): void
    {
        unset($this->draft, $this->review, $this->matches, $this->identifiedAndCurrent);
    }
}
