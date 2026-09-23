<?php

namespace Database\Factories;

use App\Enums\ReasonCode;
use App\Models\Part;
use App\Models\StockTransaction;
use App\Models\User;
use App\Models\WithdrawalOverride;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<WithdrawalOverride>
 */
class WithdrawalOverrideFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'stock_transaction_id' => StockTransaction::factory(),
            'part_id' => Part::factory(),
            'verified_qty' => '10.000',
            'recorded_on_hand' => '0.000',
            'reason_code' => ReasonCode::FoundStock,
            'authorised_by_user_id' => User::factory(),
            'authorised_at' => CarbonImmutable::now(),
        ];
    }
}
