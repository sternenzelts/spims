<?php

use App\Domain\Withdrawals\Exceptions\WithdrawalRefused;
use App\Domain\Withdrawals\WithdrawalService;
use App\Enums\TransactionStatus;
use App\Models\AuditLog;
use App\Models\Equipment;
use App\Models\Part;
use App\Models\Personnel;
use App\Models\StockTransaction;
use App\Models\User;
use App\Models\WithdrawalOverride;
use App\Models\WithdrawalPurpose;
use Carbon\CarbonImmutable;

beforeEach(function () {
    configureInstall();
    $this->custodian = User::factory()->create();
    $this->service = app(WithdrawalService::class);
    $this->purpose = WithdrawalPurpose::query()->where('is_active', true)->orderBy('sort_order')->firstOrFail();
    $this->equipment = Equipment::factory()->create();
});

/**
 * A draft that has been scanned: identified_at set and personnel attached, as Task 4 leaves it.
 */
function identified(StockTransaction $draft): StockTransaction
{
    $draft->forceFill([
        'personnel_id' => Personnel::factory()->create()->id,
        'identified_at' => CarbonImmutable::now(),
    ])->save();

    return $draft;
}

it('opens one draft per custodian and returns the same one again', function () {
    $first = $this->service->openDraft($this->custodian);
    $second = $this->service->openDraft($this->custodian);

    expect($second->is($first))->toBeTrue()
        ->and($first->status)->toBe(TransactionStatus::Draft)
        ->and(StockTransaction::query()->count())->toBe(1);
});

it('gives each custodian their own draft', function () {
    $other = User::factory()->create();

    expect($this->service->openDraft($this->custodian)->is($this->service->openDraft($other)))->toBeFalse();
});

it('adds a line that removes stock', function () {
    $draft = $this->service->openDraft($this->custodian);
    $part = Part::factory()->create();

    $line = $this->service->addLine($draft, $part, '3.5');

    expect($line->part_id)->toBe($part->id)
        ->and($line->quantity)->toBe('3.500')
        ->and($line->direction)->toBe(-1)
        ->and($line->line_no)->toBe(1);
});

it('merges the same part entered twice into one line with the total', function () {
    $draft = $this->service->openDraft($this->custodian);
    $part = Part::factory()->create();

    $this->service->addLine($draft, $part, '2');
    $this->service->addLine($draft, $part, '1.250');

    expect($draft->lines()->count())->toBe(1)
        ->and($draft->lines()->firstOrFail()->quantity)->toBe('3.250');
});

it('numbers a second part as the next line', function () {
    $draft = $this->service->openDraft($this->custodian);

    $this->service->addLine($draft, Part::factory()->create(), '1');
    $second = $this->service->addLine($draft, Part::factory()->create(), '1');

    expect($second->line_no)->toBe(2);
});

it('refuses an inactive part, naming it', function () {
    $draft = $this->service->openDraft($this->custodian);
    $part = Part::factory()->create(['is_active' => false, 'part_number' => 'BRG-6204']);

    expect(fn () => $this->service->addLine($draft, $part, '1'))
        ->toThrow(WithdrawalRefused::class, 'BRG-6204');
});

it('refuses a quantity of zero or less', function (string $quantity) {
    $draft = $this->service->openDraft($this->custodian);

    expect(fn () => $this->service->addLine($draft, Part::factory()->create(), $quantity))
        ->toThrow(WithdrawalRefused::class);
})->with(['zero' => '0', 'negative' => '-1', 'blank' => ' ', 'words' => 'two']);

it('changes and removes a line', function () {
    $draft = $this->service->openDraft($this->custodian);
    $line = $this->service->addLine($draft, Part::factory()->create(), '5');

    $this->service->setLineQuantity($draft, $line, '2');
    expect($line->fresh()->quantity)->toBe('2.000');

    $this->service->removeLine($draft, $line);
    expect($draft->lines()->count())->toBe(0);
});

it('refuses a line that belongs to another draft', function () {
    $draft = $this->service->openDraft($this->custodian);
    $other = StockTransaction::factory()->withLines([[Part::factory()->create(), '1']])->create();

    expect(fn () => $this->service->removeLine($draft, $other->lines()->firstOrFail()))
        ->toThrow(WithdrawalRefused::class);
});

it('sets the equipment, purpose and remarks of the whole withdrawal', function () {
    $draft = $this->service->openDraft($this->custodian);

    $this->service->setContext($draft, $this->equipment, $this->purpose, 'Bearing seized');

    expect($draft->fresh())
        ->equipment_id->toBe($this->equipment->id)
        ->purpose_id->toBe($this->purpose->id)
        ->remarks->toBe('Bearing seized');
});

it('sets the equipment and purpose of one line', function () {
    $draft = $this->service->openDraft($this->custodian);
    $line = $this->service->addLine($draft, Part::factory()->create(), '1');
    $other = Equipment::factory()->create();

    $this->service->setLineContext($draft, $line, $other, $this->purpose);

    expect($line->fresh())->equipment_id->toBe($other->id)->purpose_id->toBe($this->purpose->id);
});

it('refuses inactive equipment and inactive purposes', function () {
    $draft = $this->service->openDraft($this->custodian);
    $retired = Equipment::factory()->create(['is_active' => false, 'code' => 'CNC-99']);
    $withdrawn = WithdrawalPurpose::query()->create(['code' => 'retired', 'name' => 'Retired purpose', 'is_active' => false]);

    expect(fn () => $this->service->setContext($draft, $retired, $this->purpose, null))
        ->toThrow(WithdrawalRefused::class, 'CNC-99')
        ->and(fn () => $this->service->setContext($draft, $this->equipment, $withdrawn, null))
        ->toThrow(WithdrawalRefused::class, 'Retired purpose');
});

it('cancels a draft and audits it', function () {
    $draft = $this->service->openDraft($this->custodian);
    $this->service->addLine($draft, Part::factory()->create(), '1');

    $this->service->cancel($draft, $this->custodian);

    expect($draft->fresh()->status)->toBe(TransactionStatus::Cancelled)
        ->and(AuditLog::query()->where('auditable_type', 'stock_transaction')->where('event', 'cancelled')->count())->toBe(1);
});

it('refuses to change a draft that is no longer a draft', function () {
    $draft = $this->service->openDraft($this->custodian);
    $this->service->cancel($draft, $this->custodian);

    expect(fn () => $this->service->addLine($draft, Part::factory()->create(), '1'))
        ->toThrow(WithdrawalRefused::class);
});

it('clears the identification on every change to the draft', function (Closure $change) {
    $draft = identified($this->service->openDraft($this->custodian));
    $line = $this->service->addLine($draft, Part::factory()->create(), '4');
    $draft = identified($draft->fresh());

    $change($this->service, $draft, $line);

    expect($draft->fresh())->identified_at->toBeNull()->personnel_id->toBeNull();
})->with([
    'adding a line' => [fn (WithdrawalService $s, StockTransaction $d) => $s->addLine($d, Part::factory()->create(), '1')],
    'changing a quantity' => [fn (WithdrawalService $s, StockTransaction $d, $line) => $s->setLineQuantity($d, $line, '1')],
    'removing a line' => [fn (WithdrawalService $s, StockTransaction $d, $line) => $s->removeLine($d, $line)],
    'setting the context' => [fn (WithdrawalService $s, StockTransaction $d) => $s->setContext($d, null, null, 'note')],
    'setting a line context' => [fn (WithdrawalService $s, StockTransaction $d, $line) => $s->setLineContext($d, $line, null, null)],
]);

it('keeps an override while the line stays within the verified quantity', function () {
    $draft = $this->service->openDraft($this->custodian);
    $part = Part::factory()->create();
    $line = $this->service->addLine($draft, $part, '5');
    WithdrawalOverride::factory()->create([
        'stock_transaction_id' => $draft->id,
        'part_id' => $part->id,
        'verified_qty' => '5.000',
    ]);

    $this->service->setLineQuantity($draft, $line, '4');

    expect($draft->overrides()->count())->toBe(1);
});

it('removes an override when the line is raised beyond the verified quantity', function () {
    $draft = $this->service->openDraft($this->custodian);
    $part = Part::factory()->create();
    $line = $this->service->addLine($draft, $part, '5');
    WithdrawalOverride::factory()->create([
        'stock_transaction_id' => $draft->id,
        'part_id' => $part->id,
        'verified_qty' => '5.000',
    ]);

    $this->service->setLineQuantity($draft, $line, '5.001');

    expect($draft->overrides()->count())->toBe(0);
});

it('removes an override when its line is removed', function () {
    $draft = $this->service->openDraft($this->custodian);
    $part = Part::factory()->create();
    $line = $this->service->addLine($draft, $part, '5');
    WithdrawalOverride::factory()->create([
        'stock_transaction_id' => $draft->id,
        'part_id' => $part->id,
        'verified_qty' => '5.000',
    ]);

    $this->service->removeLine($draft, $line);

    expect($draft->overrides()->count())->toBe(0);
});

it('refuses to confirm a draft that is not complete', function (Closure $prepare, string $expected) {
    $draft = $this->service->openDraft($this->custodian);
    $prepare($this->service, $draft, $this->equipment, $this->purpose);

    expect(fn () => $this->service->guardReadyToConfirm($draft->fresh()))
        ->toThrow(WithdrawalRefused::class, $expected);
})->with([
    'no lines' => [fn () => null, 'at least one spare part'],
    'no equipment' => [
        function (WithdrawalService $s, StockTransaction $d, Equipment $e, WithdrawalPurpose $p) {
            $s->addLine($d, Part::factory()->create(), '1');
            $s->setContext($d, null, $p, null);
        },
        'Equipment is required',
    ],
    'no purpose' => [
        function (WithdrawalService $s, StockTransaction $d, Equipment $e) {
            $s->addLine($d, Part::factory()->create(), '1');
            $s->setContext($d, $e, null, null);
        },
        'purpose is required',
    ],
]);

it('requires remarks when the purpose asks for them', function () {
    // Seeded by Plan 1 Task 4; the PRD makes this the purpose that requires remarks by default.
    $purpose = WithdrawalPurpose::query()->where('requires_remarks', true)->firstOrFail();
    $draft = $this->service->openDraft($this->custodian);
    $this->service->addLine($draft, Part::factory()->create(), '1');
    $this->service->setContext($draft, $this->equipment, $purpose, null);

    expect(fn () => $this->service->guardReadyToConfirm($draft->fresh()))
        ->toThrow(WithdrawalRefused::class, 'Other Maintenance Activity');

    $this->service->setContext($draft, $this->equipment, $purpose, 'Trial fit on the new jig');

    // Remarks are satisfied; the next thing missing is the badge scan of FR-SPIMS-007.
    expect(fn () => $this->service->guardReadyToConfirm($draft->fresh()))
        ->toThrow(WithdrawalRefused::class, 'scan their badge');
});
