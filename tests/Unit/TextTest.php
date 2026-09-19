<?php

use App\Support\Text;

it('trims text and treats blank text as missing', function (?string $typed, ?string $stored) {
    expect(Text::nullIfBlank($typed))->toBe($stored);
})->with([
    'nothing' => [null, null],
    'only spaces' => ['   ', null],
    'padded text' => ['  Calamba, Laguna ', 'Calamba, Laguna'],
]);
