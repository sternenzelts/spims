<?php

use App\Domain\Withdrawals\WithdrawalService;
use App\Enums\LevelWarning;
use App\Models\User;
use App\Models\WithdrawalOverride;

beforeEach(function () {
    configureInstall();
    $this->custodian = User::factory()->create();
    $this->service = app(WithdrawalService::class);
});

it('shows the balance each line will leave', function () {
    $part = partWithStock('20', ['min_level' => '2', 'critical_level' => '1']);
    $draft = $this->service->openDraft($this->custodian);
    $this->service->addLine($draft, $part, '7.5');

    $review = $this->service->review($draft);

    expect($review->lines)->toHaveCount(1)
        ->and($review->lines[0]->onHand)->toBe('20.000')
        ->and($review->lines[0]->resulting)->toBe('12.500')
        ->and($review->lines[0]->isShort)->toBeFalse()
        ->and($review->lines[0]->level)->toBeNull()
        ->and($review->isShort())->toBeFalse();
});

it('flags a line that exceeds the available stock', function () {
    $part = partWithStock('3', ['part_number' => 'BRG-6204']);
    $draft = $this->service->openDraft($this->custodian);
    $this->service->addLine($draft, $part, '5');

    $review = $this->service->review($draft);

    expect($review->lines[0]->isShort)->toBeTrue()
        ->and($review->lines[0]->needsOverride())->toBeTrue()
        ->and($review->lines[0]->level)->toBeNull()
        ->and($review->isShort())->toBeTrue()
        ->and($review->needsOverride())->toBeTrue()
        ->and($review->shortPartNumbers())->toBe(['BRG-6204']);
});

it('shows the balance an authorised override will really leave', function () {
    $part = partWithStock('3');
    $draft = $this->service->openDraft($this->custodian);
    $this->service->addLine($draft, $part, '5');
    WithdrawalOverride::factory()->create([
        'stock_transaction_id' => $draft->id,
        'part_id' => $part->id,
        'verified_qty' => '8.000',
        'recorded_on_hand' => '3.000',
    ]);

    $review = $this->service->review($draft);

    expect($review->lines[0]->isShort)->toBeTrue()
        ->and($review->lines[0]->needsOverride())->toBeFalse()
        ->and($review->lines[0]->onHand)->toBe('3.000')
        ->and($review->lines[0]->resulting)->toBe('3.000')
        ->and($review->needsOverride())->toBeFalse();
});

it('warns when a line takes a part to or below a level', function (string $onHand, string $quantity, ?LevelWarning $expected) {
    $part = partWithStock($onHand, ['min_level' => '5', 'critical_level' => '2']);
    $draft = $this->service->openDraft($this->custodian);
    $this->service->addLine($draft, $part, $quantity);

    expect($this->service->review($draft)->lines[0]->level)->toBe($expected);
})->with([
    'well above the minimum' => ['20', '1', null],
    'one above the minimum' => ['20', '14', null],
    'exactly the minimum' => ['20', '15', LevelWarning::Minimum],
    'below the minimum' => ['20', '16', LevelWarning::Minimum],
    'exactly the critical level' => ['20', '18', LevelWarning::Critical],
    'below the critical level' => ['20', '19', LevelWarning::Critical],
    'down to nothing' => ['20', '20', LevelWarning::Critical],
]);

it('reviews an empty draft as empty', function () {
    $draft = $this->service->openDraft($this->custodian);

    expect($this->service->review($draft)->lines)->toBe([])
        ->and($this->service->review($draft)->isShort())->toBeFalse();
});

it('reviews lines in line order', function () {
    $first = partWithStock('10', ['part_number' => 'AAA-1']);
    $second = partWithStock('10', ['part_number' => 'BBB-2']);
    $draft = $this->service->openDraft($this->custodian);
    $this->service->addLine($draft, $second, '1');
    $this->service->addLine($draft, $first, '1');

    expect(array_map(fn ($line) => $line->line->part->part_number, $this->service->review($draft)->lines))
        ->toBe(['BBB-2', 'AAA-1']);
});
