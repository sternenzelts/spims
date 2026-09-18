<?php

namespace Database\Factories;

use App\Enums\IdentificationMethod;
use App\Enums\ReasonCode;
use App\Enums\TransactionStatus;
use App\Enums\TransactionType;
use App\Models\Equipment;
use App\Models\Part;
use App\Models\Personnel;
use App\Models\StockTransaction;
use App\Models\User;
use App\Models\WithdrawalPurpose;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;
use LogicException;

/**
 * Draft stock transactions for tests. The default is a fully attributed draft withdrawal.
 *
 * @extends Factory<StockTransaction>
 */
class StockTransactionFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => TransactionType::Withdrawal,
            'status' => TransactionStatus::Draft,
            'custodian_user_id' => User::factory(),
            'personnel_id' => Personnel::factory(),
            'equipment_id' => Equipment::factory(),
            'purpose_id' => fn () => WithdrawalPurpose::query()->orderBy('sort_order')->valueOrFail('id'),
            'identification_method' => IdentificationMethod::BadgeScan,
            'occurred_at' => CarbonImmutable::now(),
            'is_backdated' => false,
        ];
    }

    public function replenishment(): static
    {
        return $this->state(fn (): array => [
            'type' => TransactionType::Replenishment,
            'personnel_id' => null,
            'equipment_id' => null,
            'purpose_id' => null,
            'identification_method' => null,
            'supplier' => fake()->company(),
            'reference_no' => fake()->unique()->numerify('DR-######'),
        ]);
    }

    public function adjustment(ReasonCode $reason = ReasonCode::Miscount): static
    {
        return $this->state([
            'type' => TransactionType::Adjustment,
            'personnel_id' => null,
            'equipment_id' => null,
            'purpose_id' => null,
            'identification_method' => null,
            'reason_code' => $reason,
        ]);
    }

    public function opening(): static
    {
        return $this->state([
            'type' => TransactionType::Opening,
            'personnel_id' => null,
            'equipment_id' => null,
            'purpose_id' => null,
            'identification_method' => null,
        ]);
    }

    /**
     * Entered later from a paper fallback form (PRD FR-SPIMS-012).
     */
    public function backdated(CarbonImmutable $occurredAt): static
    {
        return $this->state(fn (array $attributes): array => [
            'occurred_at' => $occurredAt,
            'is_backdated' => true,
            'backdate_reason' => 'Network outage; entered from the paper fallback form',
            'paper_form_no' => fake()->unique()->numerify('PF-######'),
            'identification_method' => $attributes['type'] === TransactionType::Withdrawal ? IdentificationMethod::PaperForm : null,
        ]);
    }

    /**
     * Add lines after the transaction is created: [part, quantity] or [part, quantity, direction].
     * Direction defaults to the type's direction; adjustment lines must state theirs.
     *
     * @param  list<array{0: Part, 1: string, 2?: int}>  $lines
     */
    public function withLines(array $lines): static
    {
        return $this->afterCreating(function (StockTransaction $transaction) use ($lines): void {
            foreach ($lines as $index => $line) {
                $direction = $line[2] ?? $transaction->type->direction()
                    ?? throw new LogicException('Adjustment lines need an explicit direction.');

                $transaction->lines()->create([
                    'line_no' => $index + 1,
                    'part_id' => $line[0]->id,
                    'quantity' => $line[1],
                    'direction' => $direction,
                ]);
            }
        });
    }
}
