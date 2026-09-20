<?php

namespace App\Domain\Imports;

use App\Domain\Ledger\StockLedger;
use App\Domain\Parts\PartRecords;
use App\Domain\Parts\StockLevels;
use App\Enums\TransactionType;
use App\Models\Part;
use App\Models\StockTransaction;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * Writes the rows of a checked import through the services the screens use: each part change is
 * audited as if made on its screen, and each opening balance is an opening movement posted by
 * StockLedger (design §4.1). Runs inside the caller's database transaction.
 */
final readonly class ImportWriter
{
    /**
     * @param  positive-int  $linesPerTransaction
     */
    public function __construct(
        private PartRecords $records,
        private StockLevels $stockLevels,
        private StockLedger $ledger,
        private int $linesPerTransaction = 200,
    ) {}

    /**
     * @return array{created: int, updated: int, opening_balances: int}
     */
    public function write(ImportPreview $preview, User $actor): array
    {
        $openingBalances = [];

        foreach ($preview->rows as $row) {
            $part = $row->creates() ? $this->create($row, $actor) : $this->update($row, $actor);

            if ($row->levels !== null) {
                // refresh(): a part just created holds only the attributes it was created with,
                // and the stock level audit snapshot reads the columns the database defaulted.
                $this->stockLevels->update($part->refresh(), $row->levels, $actor);
            }

            if ($row->openingQuantity !== null) {
                $openingBalances[] = [$part->id, $row->openingQuantity];
            }
        }

        // Ascending part id across every chunk, so an import takes its stock locks in the same
        // order as every other posting and cannot deadlock against a station (design §5.2).
        usort($openingBalances, fn (array $first, array $second): int => $first[0] <=> $second[0]);

        foreach (array_chunk($openingBalances, $this->linesPerTransaction) as $lines) {
            $this->postOpeningBalances($lines, $actor);
        }

        return [
            'created' => $preview->creates(),
            'updated' => $preview->updates(),
            'opening_balances' => count($openingBalances),
        ];
    }

    private function create(ImportRow $row, User $actor): Part
    {
        return $this->records->create([
            'part_number' => $row->partNumber,
            'description' => $row->details['description'] ?? '',
            'unit_of_measure' => $row->details['unit_of_measure'] ?? '',
            'default_location_id' => $row->details['default_location_id'] ?? null,
        ], $row->equipmentIds ?? [], $actor);
    }

    private function update(ImportRow $row, User $actor): Part
    {
        $part = Part::query()->findOrFail($row->partId);

        $this->records->update($part, $row->details, $row->equipmentIds, $actor);

        return $part;
    }

    /**
     * One opening transaction of up to linesPerTransaction lines, so each posting locks a bounded
     * number of stock rows in one ordered statement (design §5.2).
     *
     * @param  list<array{0: int, 1: string}>  $lines  part id and positive quantity
     */
    private function postOpeningBalances(array $lines, User $actor): void
    {
        $transaction = StockTransaction::query()->create([
            'type' => TransactionType::Opening,
            'custodian_user_id' => $actor->id,
            'occurred_at' => CarbonImmutable::now(),
            'is_backdated' => false,
        ]);

        foreach ($lines as $index => [$partId, $quantity]) {
            $transaction->lines()->create([
                'line_no' => $index + 1,
                'part_id' => $partId,
                'quantity' => $quantity,
                'direction' => 1,
            ]);
        }

        $this->ledger->complete($transaction, $actor);
    }
}
