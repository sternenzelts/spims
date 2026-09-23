<?php

namespace App\Domain\Replenishments;

use App\Domain\Audit\AuditLogger;
use App\Domain\Replenishments\Exceptions\ReplenishmentRefused;
use App\Domain\Settings\InstallSettings;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\StockTransaction;
use App\Models\StockTransactionLine;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * Spare parts received into the storeroom (PRD FR-SPIMS-009, design §5.4). Positive lines, no
 * personnel and no badge: the accountability here is the supplier and the delivery reference.
 * Nothing in this class changes a stock balance — StockLedger::complete() does that.
 */
final readonly class ReplenishmentService
{
    public function __construct(
        private AuditLogger $audit,
        private InstallSettings $settings,
    ) {}

    /**
     * The custodian's open replenishment draft, created if they have none. Independent of their
     * withdrawal draft: the two screens are used one after the other all shift.
     */
    public function openDraft(User $custodian): StockTransaction
    {
        $draft = StockTransaction::query()
            ->where('custodian_user_id', $custodian->id)
            ->where('type', TransactionType::Replenishment)
            ->where('status', TransactionStatus::Draft)
            ->orderBy('id')
            ->first();

        if ($draft instanceof StockTransaction) {
            return $draft;
        }

        $draft = StockTransaction::query()->create([
            'type' => TransactionType::Replenishment,
            'custodian_user_id' => $custodian->id,
            'occurred_at' => CarbonImmutable::now(),
        ]);

        // status is not fillable and comes from the column default, and a just-created model holds
        // only the attributes it was created with, so it reads null until the model is refreshed.
        return $draft->refresh();
    }

    /**
     * FR-SPIMS-009 step 2. The reference is stored trimmed and uppercased so DR-1 and dr-1 are one
     * reference, which is what makes the duplicate warning below reliable.
     *
     * @param  string  $receivedOn  a Y-m-d date, read in the install time zone
     *
     * @throws ReplenishmentRefused
     */
    public function setDelivery(StockTransaction $draft, string $supplier, string $referenceNo, string $receivedOn): void
    {
        $this->guardDraft($draft);

        $supplier = trim($supplier);
        $referenceNo = Str::upper(trim($referenceNo));

        if ($supplier === '') {
            throw ReplenishmentRefused::supplierRequired();
        }

        if ($referenceNo === '') {
            throw ReplenishmentRefused::referenceRequired();
        }

        // Compared in the install time zone: at 00:30 in Manila it is still yesterday in UTC, and
        // the early shift must not be told that today's delivery is in the future.
        $today = CarbonImmutable::now()->setTimezone($this->settings->timezone())->toDateString();

        if ($receivedOn > $today) {
            throw ReplenishmentRefused::receivedInFuture();
        }

        if ($receivedOn < $today) {
            throw ReplenishmentRefused::receivedEarlier();
        }

        $draft->forceFill(['supplier' => $supplier, 'reference_no' => $referenceNo])->save();
    }

    /**
     * @throws ReplenishmentRefused
     */
    public function addLine(StockTransaction $draft, Part $part, string $quantity, ?string $unitCost): StockTransactionLine
    {
        $this->guardDraft($draft);
        $amount = $this->quantity($quantity);
        $cost = $this->unitCost($unitCost);

        if (! $part->is_active) {
            throw ReplenishmentRefused::inactivePart($part);
        }

        $line = $draft->lines()->where('part_id', $part->id)->first();

        if ($line instanceof StockTransactionLine) {
            $line->forceFill([
                'quantity' => (string) BigDecimal::of($line->quantity)->plus($amount),
                'unit_cost' => $cost ?? $line->unit_cost,
            ])->save();

            return $line;
        }

        return $draft->lines()->create([
            'line_no' => ((int) $draft->lines()->max('line_no')) + 1,
            'part_id' => $part->id,
            'quantity' => (string) $amount,
            // A replenishment always adds stock (TransactionType::Replenishment->direction()).
            'direction' => 1,
            'unit_cost' => $cost,
        ]);
    }

    /**
     * @throws ReplenishmentRefused
     */
    public function setLineQuantity(StockTransaction $draft, StockTransactionLine $line, string $quantity, ?string $unitCost): void
    {
        $this->guardDraft($draft);
        $this->guardLineOnDraft($draft, $line);

        $line->forceFill([
            'quantity' => (string) $this->quantity($quantity),
            'unit_cost' => $this->unitCost($unitCost),
        ])->save();
    }

    /**
     * @throws ReplenishmentRefused
     */
    public function removeLine(StockTransaction $draft, StockTransactionLine $line): void
    {
        $this->guardDraft($draft);
        $this->guardLineOnDraft($draft, $line);

        $line->delete();
    }

    /**
     * @throws ReplenishmentRefused
     */
    public function cancel(StockTransaction $draft, User $actor): void
    {
        $this->guardDraft($draft);

        $draft->forceFill(['status' => TransactionStatus::Cancelled])->save();

        $this->audit->record(
            'cancelled',
            $draft,
            ['status' => TransactionStatus::Draft->value],
            ['status' => TransactionStatus::Cancelled->value],
            $actor,
        );
    }

    /**
     * @throws ReplenishmentRefused
     */
    public function guardReadyToConfirm(StockTransaction $draft): void
    {
        $this->guardDraft($draft);

        if ($draft->supplier === null || trim($draft->supplier) === '') {
            throw ReplenishmentRefused::supplierRequired();
        }

        if ($draft->reference_no === null || trim($draft->reference_no) === '') {
            throw ReplenishmentRefused::referenceRequired();
        }

        if ($draft->lines()->count() === 0) {
            throw ReplenishmentRefused::noLines();
        }
    }

    /**
     * FR-SPIMS-009 alternate flow: the same reference already recorded for the same supplier. This
     * warns, it does not refuse — one reference legitimately covers two deliveries, and the same DR
     * number recurs across suppliers.
     */
    public function duplicateDelivery(StockTransaction $draft): ?StockTransaction
    {
        if ($draft->supplier === null || $draft->reference_no === null) {
            return null;
        }

        return StockTransaction::query()
            ->where('type', TransactionType::Replenishment)
            ->where('status', TransactionStatus::Completed)
            ->whereKeyNot($draft->id)
            ->whereRaw('lower(supplier) = ?', [Str::lower(trim($draft->supplier))])
            ->where('reference_no', $draft->reference_no)
            ->orderByDesc('id')
            ->first();
    }

    /**
     * FR-SPIMS-009 alternate flow: the parts this delivery takes above their maximum level. A part
     * with no maximum never appears.
     *
     * @return list<Part>
     */
    public function overMaximum(StockTransaction $draft): array
    {
        $over = [];

        foreach ($draft->lines()->with('part.stock')->orderBy('line_no')->get() as $line) {
            $part = $line->part;

            if ($part->max_level === null) {
                continue;
            }

            $stock = $part->stock;
            $onHand = BigDecimal::of($stock instanceof PartStock ? $stock->on_hand : '0');

            if ($onHand->plus($line->quantity)->isGreaterThan($part->max_level)) {
                $over[] = $part;
            }
        }

        return $over;
    }

    /**
     * @throws ReplenishmentRefused
     */
    private function quantity(string $quantity): BigDecimal
    {
        try {
            $amount = BigDecimal::of(trim($quantity))->toScale(3);
        } catch (MathException) {
            throw ReplenishmentRefused::quantityNotPositive($quantity);
        }

        if (! $amount->isPositive()) {
            throw ReplenishmentRefused::quantityNotPositive($quantity);
        }

        return $amount;
    }

    /**
     * @throws ReplenishmentRefused
     */
    private function unitCost(?string $unitCost): ?string
    {
        if ($unitCost === null || trim($unitCost) === '') {
            return null;
        }

        try {
            $cost = BigDecimal::of(trim($unitCost))->toScale(4);
        } catch (MathException) {
            throw ReplenishmentRefused::unitCostNegative($unitCost);
        }

        if ($cost->isNegative()) {
            throw ReplenishmentRefused::unitCostNegative($unitCost);
        }

        return (string) $cost;
    }

    /**
     * @throws ReplenishmentRefused
     */
    private function guardDraft(StockTransaction $draft): void
    {
        if ($draft->status !== TransactionStatus::Draft) {
            throw ReplenishmentRefused::notADraft($draft);
        }
    }

    /**
     * @throws ReplenishmentRefused
     */
    private function guardLineOnDraft(StockTransaction $draft, StockTransactionLine $line): void
    {
        if ($line->stock_transaction_id !== $draft->id) {
            throw ReplenishmentRefused::lineNotOnDraft();
        }
    }
}
