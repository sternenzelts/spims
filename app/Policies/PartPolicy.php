<?php

namespace App\Policies;

use App\Enums\Permission;
use App\Models\Part;
use App\Models\User;

/**
 * PRD §8 for spare part records. A custodian creates parts only when receiving them (Plan 3,
 * PartsCreateOnReceipt) and edits the basic fields only (PartsEditBasic).
 */
final class PartPolicy
{
    public function create(User $user): bool
    {
        return $user->can(Permission::PartsCreate);
    }

    public function update(User $user, Part $part): bool
    {
        return $user->can(Permission::PartsEdit) || $user->can(Permission::PartsEditBasic);
    }

    /**
     * Part number, status and equipment links, beyond the basic fields.
     */
    public function updateAllFields(User $user, Part $part): bool
    {
        return $user->can(Permission::PartsEdit);
    }

    public function manageStockLevels(User $user, Part $part): bool
    {
        return $user->can(Permission::StockLevelsManage);
    }
}
