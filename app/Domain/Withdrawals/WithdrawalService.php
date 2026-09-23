<?php

namespace App\Domain\Withdrawals;

use App\Domain\Audit\AuditLogger;
use App\Domain\Withdrawals\Exceptions\WithdrawalRefused;
use App\Enums\LevelWarning;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Equipment;
use App\Models\Part;
use App\Models\PartStock;
use App\Models\StockTransaction;
use App\Models\StockTransactionLine;
use App\Models\User;
use App\Models\WithdrawalOverride;
use App\Models\WithdrawalPurpose;
use Brick\Math\BigDecimal;
use Brick\Math\Exception\MathException;
use Carbon\CarbonImmutable;

/**
 * A draft withdrawal and everything that can be done to it before the badge scan
 * (PRD FR-SPIMS-006). Nothing here changes a stock balance: that is StockLedger's alone.
 */
final readonly class WithdrawalService
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * The custodian's open draft withdrawal, created if they have none. One per custodian, so a
     * reload, a second tab or a connection that dropped mid-transaction resumes the same cart
     * (NFR-SPIMS-007) instead of quietly starting a second one. Two requests racing here would
     * create two drafts; the older one always wins afterwards, and neither holds stock.
     */
    public function openDraft(User $custodian): StockTransaction
    {
        $draft = StockTransaction::query()
            ->where('custodian_user_id', $custodian->id)
            ->where('type', TransactionType::Withdrawal)
            ->where('status', TransactionStatus::Draft)
            ->orderBy('id')
            ->first();

        if ($draft instanceof StockTransaction) {
            return $draft;
        }

        $draft = StockTransaction::query()->create([
            'type' => TransactionType::Withdrawal,
            'custodian_user_id' => $custodian->id,
            // Overwritten with the commit time unless the entry is backdated (Plan 4).
            'occurred_at' => CarbonImmutable::now(),
        ]);

        // status is not fillable and comes from the column default, and a just-created model holds
        // only the attributes it was created with, so it reads null until the model is refreshed.
        return $draft->refresh();
    }

    /**
     * Add a part, or add to the line it already has: FR-SPIMS-006 combines the same spare part
     * entered twice into one line with the total, and the table enforces it with
     * unique (stock_transaction_id, part_id).
     *
     * @throws WithdrawalRefused
     */
    public function addLine(StockTransaction $draft, Part $part, string $quantity): StockTransactionLine
    {
        $this->guardDraft($draft);
        $amount = $this->quantity($quantity);

        if (! $part->is_active) {
            throw WithdrawalRefused::inactivePart($part);
        }

        $line = $draft->lines()->where('part_id', $part->id)->first();

        if ($line instanceof StockTransactionLine) {
            $line->forceFill(['quantity' => (string) BigDecimal::of($line->quantity)->plus($amount)])->save();
        } else {
            $line = $draft->lines()->create([
                'line_no' => ((int) $draft->lines()->max('line_no')) + 1,
                'part_id' => $part->id,
                'quantity' => (string) $amount,
                // A withdrawal always removes stock (TransactionType::Withdrawal->direction()).
                'direction' => -1,
            ]);
        }

        $this->invalidate($draft);

        return $line;
    }

    /**
     * @throws WithdrawalRefused
     */
    public function setLineQuantity(StockTransaction $draft, StockTransactionLine $line, string $quantity): void
    {
        $this->guardDraft($draft);
        $this->guardLineOnDraft($draft, $line);

        $line->forceFill(['quantity' => (string) $this->quantity($quantity)])->save();

        $this->invalidate($draft);
    }

    /**
     * @throws WithdrawalRefused
     */
    public function removeLine(StockTransaction $draft, StockTransactionLine $line): void
    {
        $this->guardDraft($draft);
        $this->guardLineOnDraft($draft, $line);

        $line->delete();

        $this->invalidate($draft);
    }

    /**
     * The equipment, purpose and remarks of the whole withdrawal (FR-SPIMS-006 step 5). Whether
     * they are all present is checked at confirmation, so a custodian can fill them in any order.
     *
     * @throws WithdrawalRefused
     */
    public function setContext(
        StockTransaction $draft,
        ?Equipment $equipment,
        ?WithdrawalPurpose $purpose,
        ?string $remarks,
    ): void {
        $this->guardDraft($draft);
        $this->guardActiveContext($equipment, $purpose);

        $remarks = $remarks === null || trim($remarks) === '' ? null : trim($remarks);

        $draft->forceFill([
            'equipment_id' => $equipment?->id,
            'purpose_id' => $purpose?->id,
            'remarks' => $remarks,
        ])->save();

        $this->invalidate($draft);
    }

    /**
     * One line for a different machine or purpose (FR-SPIMS-006 alternate flow). A line's own
     * equipment and purpose win over the withdrawal's when the movement is written.
     *
     * @throws WithdrawalRefused
     */
    public function setLineContext(
        StockTransaction $draft,
        StockTransactionLine $line,
        ?Equipment $equipment,
        ?WithdrawalPurpose $purpose,
    ): void {
        $this->guardDraft($draft);
        $this->guardLineOnDraft($draft, $line);
        $this->guardActiveContext($equipment, $purpose);

        $line->forceFill(['equipment_id' => $equipment?->id, 'purpose_id' => $purpose?->id])->save();

        $this->invalidate($draft);
    }

    /**
     * An abandoned withdrawal (FR-SPIMS-006 alternate flow). The draft is kept, as Cancelled:
     * nothing is deleted, and no stock movement was ever written.
     *
     * @throws WithdrawalRefused
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
     * Everything FR-SPIMS-006 requires of a withdrawal before it may be confirmed. Task 4 adds the
     * identification checks of FR-SPIMS-007 to this method.
     *
     * @throws WithdrawalRefused
     */
    public function guardReadyToConfirm(StockTransaction $draft): void
    {
        $this->guardDraft($draft);

        if ($draft->lines()->count() === 0) {
            throw WithdrawalRefused::noLines();
        }

        if ($draft->equipment_id === null) {
            throw WithdrawalRefused::equipmentRequired();
        }

        $purpose = $draft->purpose;

        if (! $purpose instanceof WithdrawalPurpose) {
            throw WithdrawalRefused::purposeRequired();
        }

        if ($purpose->requires_remarks && ($draft->remarks === null || trim($draft->remarks) === '')) {
            throw WithdrawalRefused::remarksRequired($purpose);
        }
    }

    /**
     * The draft as the custodian sees it at review (FR-SPIMS-006 steps 4 and 7): each line's
     * recorded balance, the balance it will leave, and its warning. Read outside any transaction
     * and holding no locks (design §5.2) — the balance that decides is the one read under lock at
     * commit.
     */
    public function review(StockTransaction $draft): WithdrawalReview
    {
        $lines = $draft->lines()->with(['part.stock', 'part.defaultLocation'])->orderBy('line_no')->get();
        $overrides = $draft->overrides()->get();
        $reviews = [];

        foreach ($lines as $line) {
            $override = $overrides->first(
                fn (WithdrawalOverride $override): bool => $override->part_id === $line->part_id,
            );

            $stock = $line->part->stock;
            $onHand = BigDecimal::of($stock instanceof PartStock ? $stock->on_hand : '0');
            $quantity = BigDecimal::of($line->quantity);
            $isShort = $onHand->isLessThan($quantity);

            // An authorised override raises the balance to the verified quantity at commit, so the
            // resulting balance the custodian is shown is the one that override will really leave.
            $base = $isShort && $override instanceof WithdrawalOverride
                ? BigDecimal::of($override->verified_qty)
                : $onHand;

            $resulting = $base->minus($quantity);

            $reviews[] = new LineReview(
                line: $line,
                onHand: (string) $onHand->toScale(3),
                resulting: (string) $resulting->toScale(3),
                isShort: $isShort,
                override: $override,
                level: $this->levelWarning($line->part, $resulting),
            );
        }

        return new WithdrawalReview($reviews);
    }

    /**
     * FR-SPIMS-006 step 7: at or below the minimum or critical level, the more severe first. A
     * short line gets none — its shortage is already the warning.
     */
    private function levelWarning(Part $part, BigDecimal $resulting): ?LevelWarning
    {
        if ($resulting->isNegative()) {
            return null;
        }

        if ($resulting->isLessThanOrEqualTo($part->critical_level)) {
            return LevelWarning::Critical;
        }

        return $resulting->isLessThanOrEqualTo($part->min_level) ? LevelWarning::Minimum : null;
    }

    /**
     * The one place a draft's identification is cleared and its stale overrides removed. Every
     * mutator above calls it, which is what makes the badge scan the last step (FR-SPIMS-007) and
     * stops an authorisation outliving the quantity it was given for (FR-SPIMS-008). Never clear
     * either of them anywhere else.
     */
    public function invalidate(StockTransaction $draft): void
    {
        $this->pruneOverrides($draft);
        $this->clearIdentification($draft);
    }

    private function clearIdentification(StockTransaction $draft): void
    {
        if ($draft->identified_at === null && $draft->personnel_id === null) {
            return;
        }

        $draft->forceFill([
            'identified_at' => null,
            'personnel_id' => null,
            'identification_method' => null,
            'identification_authorised_by_user_id' => null,
            'identification_reason' => null,
        ])->save();
    }

    /**
     * FR-SPIMS-008: an authorisation covers a verified quantity. If its line is gone, or its
     * quantity has been raised beyond what the supervisor verified, the authorisation goes too and
     * a new one is needed.
     */
    private function pruneOverrides(StockTransaction $draft): void
    {
        $quantities = $draft->lines()->pluck('quantity', 'part_id');

        foreach ($draft->overrides()->get() as $override) {
            $quantity = $quantities->get($override->part_id);

            if ($quantity === null || BigDecimal::of($quantity)->isGreaterThan($override->verified_qty)) {
                $override->delete();
            }
        }
    }

    /**
     * @throws WithdrawalRefused
     */
    private function quantity(string $quantity): BigDecimal
    {
        try {
            $amount = BigDecimal::of(trim($quantity))->toScale(3);
        } catch (MathException) {
            throw WithdrawalRefused::quantityNotPositive($quantity);
        }

        if (! $amount->isPositive()) {
            throw WithdrawalRefused::quantityNotPositive($quantity);
        }

        return $amount;
    }

    /**
     * @throws WithdrawalRefused
     */
    private function guardDraft(StockTransaction $draft): void
    {
        if ($draft->status !== TransactionStatus::Draft) {
            throw WithdrawalRefused::notADraft($draft);
        }
    }

    /**
     * @throws WithdrawalRefused
     */
    private function guardLineOnDraft(StockTransaction $draft, StockTransactionLine $line): void
    {
        if ($line->stock_transaction_id !== $draft->id) {
            throw WithdrawalRefused::lineNotOnDraft();
        }
    }

    /**
     * @throws WithdrawalRefused
     */
    private function guardActiveContext(?Equipment $equipment, ?WithdrawalPurpose $purpose): void
    {
        if ($equipment instanceof Equipment && ! $equipment->is_active) {
            throw WithdrawalRefused::inactiveEquipment($equipment);
        }

        if ($purpose instanceof WithdrawalPurpose && ! $purpose->is_active) {
            throw WithdrawalRefused::inactivePurpose($purpose);
        }
    }
}
