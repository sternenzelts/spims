<?php

use App\Domain\Ledger\TransactionNumberer;
use App\Enums\TransactionType;
use Carbon\CarbonImmutable;

it('refuses to issue a transaction number outside a database transaction', function () {
    expect(fn () => app(TransactionNumberer::class)->next(TransactionType::Withdrawal, CarbonImmutable::now()))
        ->toThrow(LogicException::class, 'inside the posting database transaction');
});
