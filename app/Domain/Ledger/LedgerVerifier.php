<?php

namespace App\Domain\Ledger;

use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * Re-derives every balance from the ledger (design §4.1, §13.3). Balance history is checked in id
 * order, which is recording order, not occurred_at order (design §4.5).
 */
final class LedgerVerifier
{
    /**
     * @return list<LedgerDiscrepancy>
     */
    public function verify(): array
    {
        return [...$this->stockCacheDiscrepancies(), ...$this->runningBalanceDiscrepancies()];
    }

    /**
     * @return list<LedgerDiscrepancy>
     */
    private function stockCacheDiscrepancies(): array
    {
        $rows = DB::select(<<<'SQL'
            select parts.id as part_id, parts.part_number, part_stock.on_hand,
                   coalesce(sum(stock_movements.quantity), 0) as ledger_sum
            from part_stock
            join parts on parts.id = part_stock.part_id
            left join stock_movements on stock_movements.part_id = part_stock.part_id
            group by parts.id, parts.part_number, part_stock.on_hand
            having part_stock.on_hand <> coalesce(sum(stock_movements.quantity), 0)
            order by parts.id
            SQL);

        return array_values(array_map(fn (stdClass $row): LedgerDiscrepancy => new LedgerDiscrepancy(
            DiscrepancyKind::StockCache,
            (int) $row->part_id,
            (string) $row->part_number,
            expected: (string) $row->ledger_sum,
            actual: (string) $row->on_hand,
        ), $rows));
    }

    /**
     * @return list<LedgerDiscrepancy>
     */
    private function runningBalanceDiscrepancies(): array
    {
        $rows = DB::select(<<<'SQL'
            select ledger.id as movement_id, ledger.part_id, parts.part_number, ledger.balance_after, ledger.running_sum
            from (
                select id, part_id, balance_after,
                       sum(quantity) over (partition by part_id order by id) as running_sum
                from stock_movements
            ) as ledger
            join parts on parts.id = ledger.part_id
            where ledger.balance_after <> ledger.running_sum
            order by ledger.id
            SQL);

        return array_values(array_map(fn (stdClass $row): LedgerDiscrepancy => new LedgerDiscrepancy(
            DiscrepancyKind::RunningBalance,
            (int) $row->part_id,
            (string) $row->part_number,
            expected: (string) $row->running_sum,
            actual: (string) $row->balance_after,
            movementId: (int) $row->movement_id,
        ), $rows));
    }
}
