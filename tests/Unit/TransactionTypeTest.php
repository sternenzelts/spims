<?php

use App\Enums\MovementType;
use App\Enums\TransactionType;

it('fixes the stock direction of every type except adjustments', function (TransactionType $type, ?int $direction, MovementType $movementType) {
    expect($type->direction())->toBe($direction)
        ->and($type->movementType())->toBe($movementType);
})->with([
    'withdrawal removes stock' => [TransactionType::Withdrawal, -1, MovementType::Withdrawal],
    'replenishment adds stock' => [TransactionType::Replenishment, 1, MovementType::Replenishment],
    'opening balance adds stock' => [TransactionType::Opening, 1, MovementType::Opening],
    'adjustment lines choose' => [TransactionType::Adjustment, null, MovementType::Adjustment],
]);
