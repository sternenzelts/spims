<?php

namespace App\Enums;

enum Permission: string
{
    case PartsView = 'parts.view';
    case MovementsView = 'movements.view';
    case PartsCreate = 'parts.create';
    case PartsCreateOnReceipt = 'parts.create-on-receipt';
    case PartsEdit = 'parts.edit';
    case PartsEditBasic = 'parts.edit-basic';
    case StockLevelsManage = 'stock-levels.manage';
    case EquipmentLocationsManage = 'equipment-locations.manage';
    case PersonnelManage = 'personnel.manage';
    case BadgesViewFull = 'badges.view-full';
    case PartsImport = 'parts.import';
    case OpeningBalancesImport = 'opening-balances.import';
    case WithdrawalsProcess = 'withdrawals.process';
    case OverridesAuthorise = 'overrides.authorise';
    case IdentificationAuthorise = 'identification.authorise';
    case ReplenishmentsRecord = 'replenishments.record';
    case AdjustmentsRecord = 'adjustments.record';
    case TransactionsReverse = 'transactions.reverse';
    case BackdatedRecordWithinLimit = 'backdated.record-within-limit';
    case BackdatedRecordBeyondLimit = 'backdated.record-beyond-limit';
    case CountsManage = 'counts.manage';
    case CountsEnter = 'counts.enter';
    case AnalyticsView = 'analytics.view';
    case StockAlertsAcknowledge = 'alerts.acknowledge-stock-level';
    case AlertsAcknowledge = 'alerts.acknowledge';
    case DashboardView = 'dashboard.view';
    case StockReportsView = 'reports.view-stock';
    case ReportsView = 'reports.view';
    case PartAuditView = 'audit.view-parts';
    case AuditView = 'audit.view';
    case UsersManage = 'users.manage';
    case SettingsManage = 'settings.manage';
    case SampleDataManage = 'sample-data.manage';

    /**
     * The roles that hold this permission, from PRD v1.0 §8. A "Conditional" cell in the PRD is a
     * narrower permission of its own, held by the conditional role and by every role with the full one:
     * PartsCreateOnReceipt, PartsEditBasic, BackdatedRecordWithinLimit, StockAlertsAcknowledge,
     * StockReportsView (reports A, E and F) and PartAuditView.
     *
     * @return list<Role>
     */
    public function roles(): array
    {
        $everyone = [Role::Custodian, Role::Supervisor, Role::Planner, Role::Admin];

        return match ($this) {
            self::PartsView,
            self::MovementsView,
            self::PartsCreateOnReceipt,
            self::PartsEditBasic,
            self::AnalyticsView,
            self::StockAlertsAcknowledge,
            self::DashboardView,
            self::StockReportsView => $everyone,

            self::PartsCreate,
            self::PartsEdit,
            self::EquipmentLocationsManage,
            self::AlertsAcknowledge,
            self::ReportsView,
            self::PartAuditView => [Role::Supervisor, Role::Planner, Role::Admin],

            self::StockLevelsManage => [Role::Planner, Role::Admin],

            self::PersonnelManage,
            self::PartsImport,
            self::AuditView => [Role::Supervisor, Role::Admin],

            self::WithdrawalsProcess,
            self::ReplenishmentsRecord,
            self::BackdatedRecordWithinLimit,
            self::CountsEnter => [Role::Custodian, Role::Supervisor],

            self::OverridesAuthorise,
            self::IdentificationAuthorise,
            self::AdjustmentsRecord,
            self::TransactionsReverse,
            self::BackdatedRecordBeyondLimit,
            self::CountsManage => [Role::Supervisor],

            self::BadgesViewFull,
            self::OpeningBalancesImport,
            self::UsersManage,
            self::SettingsManage,
            self::SampleDataManage => [Role::Admin],
        };
    }
}
