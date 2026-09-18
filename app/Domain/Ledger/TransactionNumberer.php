<?php

namespace App\Domain\Ledger;

use App\Domain\Settings\InstallSettings;
use App\Enums\TransactionType;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use LogicException;

final readonly class TransactionNumberer
{
    public function __construct(private InstallSettings $settings) {}

    /**
     * The next number for a transaction type, e.g. WD-2026-000123 (PRD FR-SPIMS-007), using the
     * install's prefix and the year in the install's time zone.
     *
     * Must run inside the posting transaction and after the stock rows are locked. The sequence row
     * stays locked until commit and a rollback returns the number, so completed transactions are
     * numbered without gaps and in commit order.
     */
    public function next(TransactionType $type, CarbonImmutable $recordedAt): string
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Transaction numbers can only be issued inside the posting database transaction.');
        }

        $prefix = $this->settings->transactionPrefix($type);
        $year = $recordedAt->setTimezone($this->settings->timezone())->year;

        $value = DB::scalar(
            'insert into transaction_number_sequences (prefix, year, last_value) values (?, ?, 1)
             on conflict (prefix, year) do update set last_value = transaction_number_sequences.last_value + 1
             returning last_value',
            [$prefix, $year],
            false,
        );

        return sprintf('%s-%d-%06d', $prefix, $year, $value);
    }
}
