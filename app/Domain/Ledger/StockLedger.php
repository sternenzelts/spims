<?php

namespace App\Domain\Ledger;

use App\Domain\Audit\AuditLogger;
use App\Domain\Ledger\Events\StockDiscrepancyReported;
use App\Domain\Ledger\Events\StockTransactionCompleted;
use App\Domain\Ledger\Exceptions\InstallNotConfigured;
use App\Domain\Ledger\Exceptions\InsufficientStock;
use App\Domain\Ledger\Exceptions\OpeningBalanceAlreadyRecorded;
use App\Domain\Ledger\Exceptions\StockBusy;
use App\Domain\Ledger\Exceptions\StockMovedSinceCount;
use App\Domain\Ledger\Exceptions\TransactionHasNoLines;
use App\Domain\Ledger\Exceptions\TransactionNotDraft;
use App\Domain\Settings\InstallSettings;
use App\Domain\Withdrawals\Exceptions\WithdrawalRefused;
use App\Domain\Withdrawals\WithdrawalIdentification;
use App\Enums\MovementType;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\PartStock;
use App\Models\StockMovement;
use App\Models\StockTransaction;
use App\Models\StockTransactionLine;
use App\Models\User;
use App\Models\WithdrawalOverride;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use LogicException;
use UnexpectedValueException;

/**
 * The only writer of stock (design §4.1, §5.1, §5.2). Every change to part_stock.on_hand goes
 * through here, together with the signed ledger movements that explain it.
 */
final readonly class StockLedger
{
    /** Postgres SQLSTATE when lock_timeout expires. Laravel does not retry it, unlike a deadlock. */
    private const LOCK_NOT_AVAILABLE = '55P03';

    public function __construct(
        private InstallSettings $settings,
        private TransactionNumberer $numberer,
        private AuditLogger $audit,
        private WithdrawalIdentification $identification,
    ) {}

    /**
     * Complete a draft in one short database transaction: lock the transaction, lock its parts'
     * stock rows in part id order, write one signed movement per line with the resulting balance,
     * update the stock cache, number the transaction and audit it. All lines post or none do.
     *
     * @throws InstallNotConfigured
     * @throws TransactionNotDraft
     * @throws TransactionHasNoLines
     * @throws InsufficientStock
     * @throws OpeningBalanceAlreadyRecorded
     * @throws StockBusy
     */
    public function complete(StockTransaction $transaction, User $actor): StockTransaction
    {
        if (! $this->settings->isReadyForStockTransactions()) {
            throw new InstallNotConfigured;
        }

        try {
            $completed = DB::transaction(fn (): StockTransaction => $this->post($transaction->id, $actor), 3);
        } catch (QueryException $exception) {
            if ($exception->getCode() === self::LOCK_NOT_AVAILABLE) {
                throw new StockBusy($exception);
            }

            throw $exception;
        }

        event(new StockTransactionCompleted($completed));

        return $completed;
    }

    private function post(int $transactionId, User $actor): StockTransaction
    {
        $this->applyLockTimeout();

        $transaction = StockTransaction::query()->whereKey($transactionId)->lockForUpdate()->firstOrFail();

        if ($transaction->status !== TransactionStatus::Draft) {
            throw new TransactionNotDraft($transaction);
        }

        // The station posts a withdrawal through completeWithOverrides(). Ignoring an
        // authorisation here would fail with InsufficientStock and look like a stock problem.
        if ($transaction->overrides()->whereNull('adjustment_transaction_id')->whereNull('discarded_at')->exists()) {
            throw new LogicException(
                "Transaction {$transaction->id} holds supervisor overrides; post it with completeWithOverrides()."
            );
        }

        $lines = $transaction->lines()->with('part')->orderBy('line_no')->get();

        if ($lines->isEmpty()) {
            throw new TransactionHasNoLines($transaction);
        }

        $balances = $this->lockBalances($lines);
        $this->guardDirections($transaction, $lines);
        $this->guardOpeningBalance($transaction, $lines);

        // One line per part is enforced by unique (stock_transaction_id, part_id), so each part's
        // resulting balance is its locked balance plus that single line.
        $resulting = [];

        foreach ($lines as $line) {
            $resulting[$line->part_id] = $balances[$line->part_id]->plus($line->signedQuantity());
        }

        $this->guardSufficientStock($lines, $balances, $resulting);

        $recordedAt = CarbonImmutable::now();
        $occurredAt = $transaction->is_backdated ? $transaction->occurred_at : $recordedAt;

        $this->writeMovements($transaction, $lines, $resulting, $occurredAt, $recordedAt);
        $this->writeBalances($resulting, $recordedAt);

        $transaction->forceFill([
            'status' => TransactionStatus::Completed,
            'transaction_no' => $this->numberer->next($transaction->type, $recordedAt),
            'recorded_at' => $recordedAt,
            'occurred_at' => $occurredAt,
        ])->save();

        $this->audit->record(
            'completed',
            $transaction,
            ['status' => TransactionStatus::Draft->value],
            ['status' => TransactionStatus::Completed->value, 'transaction_no' => $transaction->transaction_no],
            $actor,
        );

        return $transaction;
    }

    /**
     * Complete a draft withdrawal together with the adjustments its supervisor overrides authorise
     * (design §5.3, PRD FR-SPIMS-008). One adjustment transaction per override, plus the
     * withdrawal, all in one database transaction under one set of stock row locks. Either every
     * row is written or none is.
     *
     * @throws InstallNotConfigured
     * @throws TransactionNotDraft
     * @throws WithdrawalRefused when the identification is missing or has expired
     * @throws StockMovedSinceCount when stock of an overridden part moved after its count
     * @throws TransactionHasNoLines
     * @throws InsufficientStock
     * @throws StockBusy
     */
    public function completeWithOverrides(StockTransaction $withdrawal, User $actor): StockTransaction
    {
        if (! $this->settings->isReadyForStockTransactions()) {
            throw new InstallNotConfigured;
        }

        try {
            /** @var array{0: StockTransaction, 1: list<StockDiscrepancyReported>} $posted */
            $posted = DB::transaction(fn (): array => $this->postWithOverrides($withdrawal->id, $actor), 3);
        } catch (QueryException $exception) {
            if ($exception->getCode() === self::LOCK_NOT_AVAILABLE) {
                throw new StockBusy($exception);
            }

            throw $exception;
        }

        [$completed, $discrepancies] = $posted;

        event(new StockTransactionCompleted($completed));

        foreach ($discrepancies as $discrepancy) {
            event($discrepancy);
        }

        return $completed;
    }

    /**
     * @return array{0: StockTransaction, 1: list<StockDiscrepancyReported>}
     */
    private function postWithOverrides(int $transactionId, User $actor): array
    {
        $this->applyLockTimeout();

        $withdrawal = StockTransaction::query()->whereKey($transactionId)->lockForUpdate()->firstOrFail();

        if ($withdrawal->type !== TransactionType::Withdrawal) {
            throw new LogicException(
                "completeWithOverrides() posts withdrawals; transaction {$withdrawal->id} is a {$withdrawal->type->value}."
            );
        }

        if ($withdrawal->status !== TransactionStatus::Draft) {
            throw new TransactionNotDraft($withdrawal);
        }

        // Checked again on the locked row, before any stock is locked: another tab can clear the
        // identification, or its time limit can pass, after the station's own check.
        $this->identification->guardCurrent($withdrawal);

        $lines = $withdrawal->lines()->with('part')->orderBy('line_no')->get();

        if ($lines->isEmpty()) {
            throw new TransactionHasNoLines($withdrawal);
        }

        // Every override's part is already a line of this withdrawal, so the union of locked parts
        // is the withdrawal's own set and the ordering of design §5.2 is unaffected.
        $balances = $this->lockBalances($lines);
        $this->guardDirections($withdrawal, $lines);

        $recordedAt = CarbonImmutable::now();
        $occurredAt = $withdrawal->is_backdated ? $withdrawal->occurred_at : $recordedAt;

        /** @var list<StockDiscrepancyReported> $discrepancies */
        $discrepancies = [];
        /** @var list<array{0: WithdrawalOverride, 1: BigDecimal}> $adjustments */
        $adjustments = [];
        /** @var list<string> $stale */
        $stale = [];

        foreach ($withdrawal->overrides()->orderBy('part_id')->get() as $override) {
            $line = $lines->firstWhere('part_id', $override->part_id);
            $onHand = $balances[$override->part_id] ?? null;

            // Its line is gone. WithdrawalService prunes these, so this is belt and braces.
            if (! $line instanceof StockTransactionLine || ! $onHand instanceof BigDecimal) {
                $override->forceFill(['discarded_at' => $recordedAt])->save();

                continue;
            }

            // Design §5.3: the locked balance already covers the line, so the withdrawal commits
            // without the adjustment and the override is discarded — but the discrepancy was real
            // and the part still needs counting, so it is reported all the same.
            if (! BigDecimal::of($line->quantity)->isGreaterThan($onHand)) {
                $override->forceFill(['discarded_at' => $recordedAt])->save();

                $this->audit->record('override_discarded', $override, null, [
                    'on_hand' => (string) $onHand->toScale(3),
                ], $actor);

                $discrepancies[] = new StockDiscrepancyReported(
                    $override->part_id, $override->id, $withdrawal->id, false,
                );

                continue;
            }

            // The staleness guard of design §5.6.1, applied to the count behind an override. Once
            // stock of the part is recorded moving after the count, the count no longer describes
            // the shelf, and adjusting to it could record stock that never existed. No movement of
            // this posting is written yet, so it cannot see itself. Timestamps are stored to the
            // second, so a movement in the same second as the authorisation counts as after it: the
            // count was taken before the authorisation.
            if (StockMovement::query()
                ->where('part_id', $override->part_id)
                ->where('recorded_at', '>=', $override->authorised_at)
                ->exists()) {
                $stale[] = $line->part->part_number;

                continue;
            }

            // Computed here, under lock, never from the recorded_on_hand the supervisor saw.
            $increase = BigDecimal::of($override->verified_qty)->minus($onHand);

            // An override adjustment may only increase the balance (design §5.3). Nothing else
            // enforces this: TransactionType::Adjustment->direction() is null, so
            // guardDirections() returns early for adjustment lines.
            if (! $increase->isPositive()) {
                continue;
            }

            $adjustments[] = [$override, $increase];
            $balances[$override->part_id] = $onHand->plus($increase);
        }

        if ($stale !== []) {
            throw new StockMovedSinceCount($stale);
        }

        // Each override posts its own single-line adjustment, so two short lines can carry two
        // different reasons (design §5.6.3).
        foreach ($adjustments as [$override, $increase]) {
            $adjustment = StockTransaction::query()->create([
                'type' => TransactionType::Adjustment,
                'custodian_user_id' => $withdrawal->custodian_user_id,
                'reason_code' => $override->reason_code,
                'remarks' => __('Supervisor override during withdrawal.'),
                'occurred_at' => $occurredAt,
            ]);

            $adjustmentLine = $adjustment->lines()->create([
                'line_no' => 1,
                'part_id' => $override->part_id,
                'quantity' => (string) $increase,
                'direction' => 1,
            ]);

            StockMovement::query()->insert([
                'part_id' => $override->part_id,
                'stock_transaction_id' => $adjustment->id,
                'stock_transaction_line_id' => $adjustmentLine->id,
                'movement_type' => MovementType::Adjustment->value,
                'quantity' => (string) $increase,
                'balance_after' => (string) $balances[$override->part_id],
                'occurred_at' => $occurredAt,
                'recorded_at' => $recordedAt,
                'personnel_id' => null,
                'equipment_id' => null,
                'purpose_id' => null,
                'custodian_user_id' => $withdrawal->custodian_user_id,
            ]);

            $adjustment->forceFill([
                'status' => TransactionStatus::Completed,
                'transaction_no' => $this->numberer->next(TransactionType::Adjustment, $recordedAt),
                'recorded_at' => $recordedAt,
            ])->save();

            $override->forceFill(['adjustment_transaction_id' => $adjustment->id])->save();

            $this->audit->record('override_posted', $override, null, [
                'quantity' => (string) $increase,
                'adjustment_transaction_no' => $adjustment->transaction_no,
            ], $actor);

            $discrepancies[] = new StockDiscrepancyReported(
                $override->part_id, $override->id, $withdrawal->id, true,
            );
        }

        // One line per part is enforced by unique (stock_transaction_id, part_id), so each part's
        // resulting balance is its locked balance — raised by any adjustment above — plus its line.
        $resulting = [];

        foreach ($lines as $line) {
            $resulting[$line->part_id] = $balances[$line->part_id]->plus($line->signedQuantity());
        }

        $this->guardSufficientStock($lines, $balances, $resulting);

        $this->writeMovements($withdrawal, $lines, $resulting, $occurredAt, $recordedAt);

        // Once, with the final map. The adjustments' balance_after values were computed above,
        // before this write, because writeBalances() is a single UPDATE keyed by part and a second
        // call would overwrite the first.
        $this->writeBalances($resulting, $recordedAt);

        $withdrawal->forceFill([
            'status' => TransactionStatus::Completed,
            'transaction_no' => $this->numberer->next(TransactionType::Withdrawal, $recordedAt),
            'recorded_at' => $recordedAt,
            'occurred_at' => $occurredAt,
        ])->save();

        $this->audit->record(
            'completed',
            $withdrawal,
            ['status' => TransactionStatus::Draft->value],
            ['status' => TransactionStatus::Completed->value, 'transaction_no' => $withdrawal->transaction_no],
            $actor,
        );

        return [$withdrawal, $discrepancies];
    }

    /**
     * A posting waits at most this long for another station's locks, then fails with StockBusy
     * instead of leaving a custodian staring at a frozen screen.
     */
    private function applyLockTimeout(): void
    {
        $timeout = Config::string('spims.lock_timeout');

        if (preg_match('/^\d+(ms|s)$/', $timeout) !== 1) {
            throw new UnexpectedValueException("spims.lock_timeout must look like 5s or 500ms, got [{$timeout}].");
        }

        DB::statement("set local lock_timeout = '{$timeout}'");
    }

    /**
     * Lock every affected stock row in one statement, ordered by part id. The same order for every
     * posting is what keeps two stations from deadlocking (design §5.2).
     *
     * @param  Collection<int, StockTransactionLine>  $lines
     * @return array<int, BigDecimal> locked on_hand by part id
     */
    private function lockBalances(Collection $lines): array
    {
        $partIds = $lines->pluck('part_id')->unique()->sort()->values();

        $stock = PartStock::query()
            ->whereIn('part_id', $partIds)
            ->orderBy('part_id')
            ->lockForUpdate()
            ->get();

        if ($stock->count() !== $partIds->count()) {
            throw new LogicException('Every part must have a part_stock row; the parts insert trigger creates it.');
        }

        return $stock
            ->mapWithKeys(fn (PartStock $row): array => [$row->part_id => BigDecimal::of($row->on_hand)])
            ->all();
    }

    /**
     * @param  Collection<int, StockTransactionLine>  $lines
     */
    private function guardDirections(StockTransaction $transaction, Collection $lines): void
    {
        $expected = $transaction->type->direction();

        foreach ($lines as $line) {
            if ($expected !== null && $line->direction !== $expected) {
                throw new LogicException("Line {$line->line_no} of a {$transaction->type->value} must have direction {$expected}.");
            }
        }
    }

    /**
     * @param  Collection<int, StockTransactionLine>  $lines
     */
    private function guardOpeningBalance(StockTransaction $transaction, Collection $lines): void
    {
        if ($transaction->type !== TransactionType::Opening) {
            return;
        }

        $partIdsWithMovements = StockMovement::query()
            ->whereIn('part_id', $lines->pluck('part_id'))
            ->distinct()
            ->pluck('part_id');

        if ($partIdsWithMovements->isNotEmpty()) {
            throw new OpeningBalanceAlreadyRecorded(array_values(
                $lines->whereIn('part_id', $partIdsWithMovements)
                    ->map(fn (StockTransactionLine $line): string => $line->part->part_number)
                    ->all(),
            ));
        }
    }

    /**
     * @param  Collection<int, StockTransactionLine>  $lines
     * @param  array<int, BigDecimal>  $balances
     * @param  array<int, BigDecimal>  $resulting
     */
    private function guardSufficientStock(Collection $lines, array $balances, array $resulting): void
    {
        $shortages = [];

        foreach ($lines as $line) {
            if ($resulting[$line->part_id]->isNegative()) {
                $shortages[] = new Shortage(
                    $line->part->part_number,
                    (string) $balances[$line->part_id]->toScale(3),
                    (string) BigDecimal::of($line->quantity)->toScale(3),
                );
            }
        }

        if ($shortages !== []) {
            throw new InsufficientStock($shortages);
        }
    }

    /**
     * @param  Collection<int, StockTransactionLine>  $lines
     * @param  array<int, BigDecimal>  $resulting
     */
    private function writeMovements(
        StockTransaction $transaction,
        Collection $lines,
        array $resulting,
        CarbonImmutable $occurredAt,
        CarbonImmutable $recordedAt,
    ): void {
        StockMovement::query()->insert($lines->map(fn (StockTransactionLine $line): array => [
            'part_id' => $line->part_id,
            'stock_transaction_id' => $transaction->id,
            'stock_transaction_line_id' => $line->id,
            'movement_type' => $transaction->type->movementType()->value,
            'quantity' => (string) $line->signedQuantity(),
            'balance_after' => (string) $resulting[$line->part_id],
            'occurred_at' => $occurredAt,
            'recorded_at' => $recordedAt,
            'personnel_id' => $transaction->personnel_id,
            'equipment_id' => $line->equipment_id ?? $transaction->equipment_id,
            'purpose_id' => $line->purpose_id ?? $transaction->purpose_id,
            'custodian_user_id' => $transaction->custodian_user_id,
        ])->all());
    }

    /**
     * One UPDATE for all parts, to keep round trips to a remote database low (design §12: under
     * 500 ms at p95 for a ten-line withdrawal).
     *
     * @param  array<int, BigDecimal>  $resulting
     */
    private function writeBalances(array $resulting, CarbonImmutable $recordedAt): void
    {
        $rows = implode(', ', array_fill(0, count($resulting), '(cast(? as bigint), cast(? as numeric))'));
        $bindings = [$recordedAt];

        foreach ($resulting as $partId => $balance) {
            $bindings[] = $partId;
            $bindings[] = (string) $balance;
        }

        DB::update(
            "update part_stock set on_hand = v.on_hand, updated_at = ?
             from (values {$rows}) as v (part_id, on_hand)
             where part_stock.part_id = v.part_id",
            $bindings,
        );
    }
}
