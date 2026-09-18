<?php

use App\Domain\Identity\Exceptions\InvalidBadgeInput;
use App\Domain\Identity\KeyboardWedgeBadgeReader;

it('normalises what a keyboard-wedge reader types', function (string $raw, string $uid) {
    expect((new KeyboardWedgeBadgeReader)->normalise($raw))->toBe($uid);
})->with([
    'trailing enter' => ["A12345678\r\n", 'A12345678'],
    'lower case' => ['a12345678', 'A12345678'],
    'separators' => ['A1:23-45 678', 'A12345678'],
    'surrounding whitespace' => ["\t 0004521987 ", '0004521987'],
]);

it('rejects input that is not a badge', function (string $raw) {
    expect(fn () => (new KeyboardWedgeBadgeReader)->normalise($raw))->toThrow(InvalidBadgeInput::class);
})->with([
    'nothing' => [''],
    'only whitespace' => ["  \n"],
    'too short' => ['A12'],
    'symbols' => ['A1234$678'],
]);
