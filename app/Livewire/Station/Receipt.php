<?php

namespace App\Livewire\Station;

use App\Enums\Permission;
use App\Livewire\Concerns\ActsAsSignedInUser;
use App\Models\StockTransaction;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * The transaction record PRD FR-SPIMS-007 lists as a postcondition, on its own route so the
 * custodian can reopen it. Badge identifiers are masked here as everywhere (NFR-SPIMS-004).
 */
#[Title('Transaction record')]
class Receipt extends Component
{
    use ActsAsSignedInUser;

    #[Locked]
    public int $transactionId;

    public function mount(StockTransaction $transaction): void
    {
        $this->authorize(Permission::MovementsView);

        $this->transactionId = $transaction->id;
    }

    #[Computed]
    public function transaction(): StockTransaction
    {
        return StockTransaction::query()
            ->with(['personnel', 'equipment', 'purpose', 'custodian', 'lines.part', 'movements'])
            ->findOrFail($this->transactionId);
    }
}
