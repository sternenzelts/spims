<?php

namespace App\Support;

use App\Enums\Permission;
use App\Models\User;

/**
 * The sidebar menu. A screen appears only to users holding its permission, so each role sees only
 * what it can open (PRD §8). Labels and group headings are translated when rendered.
 */
final class Navigation
{
    /**
     * @var list<array{group: string, label: string, icon: string, route: string, active: string, permission: Permission}>
     */
    private const array ITEMS = [
        ['group' => 'Storeroom', 'label' => 'Dashboard', 'icon' => 'home', 'route' => 'dashboard', 'active' => 'dashboard', 'permission' => Permission::DashboardView],
        ['group' => 'Storeroom', 'label' => 'Spare parts', 'icon' => 'cube', 'route' => 'parts.index', 'active' => 'parts.*', 'permission' => Permission::PartsView],
        ['group' => 'Master data', 'label' => 'Storage locations', 'icon' => 'map-pin', 'route' => 'storage-locations.index', 'active' => 'storage-locations.*', 'permission' => Permission::EquipmentLocationsManage],
        ['group' => 'Master data', 'label' => 'Equipment', 'icon' => 'wrench-screwdriver', 'route' => 'equipment.index', 'active' => 'equipment.*', 'permission' => Permission::EquipmentLocationsManage],
        ['group' => 'Master data', 'label' => 'Personnel', 'icon' => 'identification', 'route' => 'personnel.index', 'active' => 'personnel.*', 'permission' => Permission::PersonnelManage],
        ['group' => 'Master data', 'label' => 'Spare part imports', 'icon' => 'arrow-up-tray', 'route' => 'imports.index', 'active' => 'imports.*', 'permission' => Permission::PartsImport],
        ['group' => 'Administration', 'label' => 'Users', 'icon' => 'users', 'route' => 'users.index', 'active' => 'users.*', 'permission' => Permission::UsersManage],
        ['group' => 'Administration', 'label' => 'Install settings', 'icon' => 'cog-6-tooth', 'route' => 'install-settings.edit', 'active' => 'install-settings.*', 'permission' => Permission::SettingsManage],
        ['group' => 'Administration', 'label' => 'Withdrawal purposes', 'icon' => 'list-bullet', 'route' => 'withdrawal-purposes.index', 'active' => 'withdrawal-purposes.*', 'permission' => Permission::SettingsManage],
    ];

    /**
     * The menu groups this user may see, in order, each with its items.
     *
     * @return array<string, list<array{label: string, icon: string, route: string, active: string}>>
     */
    public function for(User $user): array
    {
        $groups = [];

        foreach (self::ITEMS as $item) {
            if ($user->can($item['permission'])) {
                $groups[$item['group']][] = [
                    'label' => $item['label'],
                    'icon' => $item['icon'],
                    'route' => $item['route'],
                    'active' => $item['active'],
                ];
            }
        }

        return $groups;
    }
}
