<?php

namespace App\Domain\Ledger;

use App\Domain\Audit\AuditLogger;
use App\Domain\Ledger\Events\StockTransactionCompleted;
use App\Domain\Ledger\Exceptions\InstallNotConfigured;
use App\Domain\Ledger\Exceptions\InsufficientStock;
use App\Domain\Ledger\Exceptions\OpeningBalanceAlreadyRecorded;
use App\Domain\Ledger\Exceptions\StockBusy;
use App\Domain\Ledger\Exceptions\TransactionHasNoLines;
use App\Domain\Ledger\Exceptions\TransactionNotDraft;
use App\Domain\Settings\InstallSettings;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\PartStock;
use App\Models\StockMovement;
use App\Models\StockTransaction;
use App\Models\StockTransactionLine;
use App\Models\User;
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
