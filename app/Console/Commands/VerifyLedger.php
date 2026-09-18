<?php

namespace App\Console\Commands;

use App\Domain\Ledger\LedgerDiscrepancy;
use App\Domain\Ledger\LedgerVerifier;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class VerifyLedger extends Command
{
    protected $signature = 'ledger:verify';

    protected $description = 'Re-derive every stock balance from the ledger and report any divergence';

    public function handle(LedgerVerifier $verifier): int
    {
        $discrepancies = $verifier->verify();

        if ($discrepancies === []) {
            $this->components->info('Ledger verified: no discrepancies.');

            return self::SUCCESS;
        }

        $this->table(
            ['Check', 'Part', 'Movement', 'Expected', 'Actual'],
            array_map(fn (LedgerDiscrepancy $discrepancy): array => [
                $discrepancy->kind->value,
                $discrepancy->partNumber,
                $discrepancy->movementId ?? '-',
                $discrepancy->expected,
                $discrepancy->actual,
            ], $discrepancies),
        );

        Log::critical('Ledger verification found discrepancies.', [
            'discrepancies' => array_map(fn (LedgerDiscrepancy $discrepancy): array => get_object_vars($discrepancy), $discrepancies),
        ]);

        $this->components->error(count($discrepancies).' ledger discrepancies found.');

        return self::FAILURE;
    }
}
