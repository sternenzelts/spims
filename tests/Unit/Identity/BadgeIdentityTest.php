<?php

use App\Domain\Identity\BadgeIdentity;

it('shows at most the first five characters and always hides the last four', function (string $uid, string $masked) {
    expect((new BadgeIdentity('pepper'))->mask($uid))->toBe($masked);
})->with([
    'proposal sample' => ['A12345678', 'A1234****'],
    'ten digits' => ['0004521987', '00045*****'],
    'six characters' => ['AB1234', 'AB****'],
    'four characters' => ['AB12', '****'],
]);

it('hashes with the install pepper, so the same badge differs between installs', function () {
    $hash = (new BadgeIdentity('microtech-pepper'))->hash('A12345678');

    expect($hash)
        ->toMatch('/^[0-9a-f]{64}$/')
        ->toBe((new BadgeIdentity('microtech-pepper'))->hash('A12345678'))
        ->not->toBe((new BadgeIdentity('customer-pepper'))->hash('A12345678'));
});

it('refuses to hash without a pepper', function (?string $pepper) {
    expect(fn () => (new BadgeIdentity($pepper))->hash('A12345678'))
        ->toThrow(RuntimeException::class, 'BADGE_PEPPER');
})->with([
    'missing' => [null],
    'empty' => [''],
]);
