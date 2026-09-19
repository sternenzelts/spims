<?php

use App\Support\AuditValue;

it('displays an audit value as a person reads it', function (mixed $value, string $display) {
    expect(AuditValue::display($value))->toBe($display);
})->with([
    'null' => [null, '—'],
    'true' => [true, 'Yes'],
    'false' => [false, 'No'],
    'a list' => [['CNC-01', 'PRS-02'], 'CNC-01, PRS-02'],
    'a string' => ['8.000', '8.000'],
]);
