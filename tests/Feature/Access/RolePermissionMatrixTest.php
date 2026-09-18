<?php

use App\Enums\Permission;
use App\Enums\Role;
use Spatie\Permission\Models\Role as RoleModel;

// PRD v1.0 §8, one row per permission. Holders read C S P A: Custodian, Supervisor, Planner,
// System Administrator; a dash means the role does not hold it.
$matrix = [
    'Search Spare Parts and View Stock Balances' => [Permission::PartsView, 'CSPA'],
    'View Movement History' => [Permission::MovementsView, 'CSPA'],
    'Create Spare Part Record' => [Permission::PartsCreate, '-SPA'],
    'Create Spare Part Record (custodian, on receipt)' => [Permission::PartsCreateOnReceipt, 'CSPA'],
    'Edit Spare Part Record' => [Permission::PartsEdit, '-SPA'],
    'Edit Spare Part Record (custodian, basic fields)' => [Permission::PartsEditBasic, 'CSPA'],
    'Manage Stock Levels and Criticality' => [Permission::StockLevelsManage, '--PA'],
    'Manage Equipment and Storage Locations' => [Permission::EquipmentLocationsManage, '-SPA'],
    'Register Personnel and Enroll Badges' => [Permission::PersonnelManage, '-S-A'],
    'View Full Badge Identifier' => [Permission::BadgesViewFull, '---A'],
    'Import Spare Parts' => [Permission::PartsImport, '-S-A'],
    'Import Opening Balances' => [Permission::OpeningBalancesImport, '---A'],
    'Prepare and Confirm Withdrawal' => [Permission::WithdrawalsProcess, 'CS--'],
    'Authorize Insufficient Stock Override' => [Permission::OverridesAuthorise, '-S--'],
    'Authorize Manual Personnel Identification' => [Permission::IdentificationAuthorise, '-S--'],
    'Record Replenishment' => [Permission::ReplenishmentsRecord, 'CS--'],
    'Record Stock Adjustment' => [Permission::AdjustmentsRecord, '-S--'],
    'Reverse Completed Transaction' => [Permission::TransactionsReverse, '-S--'],
    'Record Backdated Transaction (within limit)' => [Permission::BackdatedRecordWithinLimit, 'CS--'],
    'Record Backdated Transaction (beyond limit)' => [Permission::BackdatedRecordBeyondLimit, '-S--'],
    'Open, Post, or Cancel Count Session' => [Permission::CountsManage, '-S--'],
    'Enter Counted Quantities' => [Permission::CountsEnter, 'CS--'],
    'View Consumption and Depletion' => [Permission::AnalyticsView, 'CSPA'],
    'Acknowledge Alerts (stock level)' => [Permission::StockAlertsAcknowledge, 'CSPA'],
    'Acknowledge Alerts (all types)' => [Permission::AlertsAcknowledge, '-SPA'],
    'View Dashboard' => [Permission::DashboardView, 'CSPA'],
    'View and Export Reports (A, E, F)' => [Permission::StockReportsView, 'CSPA'],
    'View and Export Reports (all)' => [Permission::ReportsView, '-SPA'],
    'View Audit History (spare parts and stock levels)' => [Permission::PartAuditView, '-SPA'],
    'View Audit History (all)' => [Permission::AuditView, '-S-A'],
    'Manage Users and Roles' => [Permission::UsersManage, '---A'],
    'Configure Install Settings' => [Permission::SettingsManage, '---A'],
    'Load or Reset Sample Data' => [Permission::SampleDataManage, '---A'],
];

dataset('prd permission matrix', $matrix);

it('grants each permission to exactly the roles in PRD §8', function (Permission $permission, string $holders) {
    $codes = ['C' => Role::Custodian, 'S' => Role::Supervisor, 'P' => Role::Planner, 'A' => Role::Admin];

    foreach ($codes as $code => $role) {
        expect(RoleModel::findByName($role->value)->hasPermissionTo($permission->value))
            ->toBe(str_contains($holders, $code), "{$role->label()} and {$permission->value}");
    }
})->with('prd permission matrix');

it('lists every permission the application defines', function () use ($matrix) {
    expect(array_map(fn (array $row): Permission => $row[0], array_values($matrix)))
        ->toEqualCanonicalizing(Permission::cases());
});
