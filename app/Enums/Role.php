<?php

namespace App\Enums;

enum Role: string
{
    case Custodian = 'custodian';
    case Supervisor = 'supervisor';
    case Planner = 'planner';
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Custodian => 'Inventory Custodian',
            self::Supervisor => 'Inventory Supervisor',
            self::Planner => 'Inventory Planner',
            self::Admin => 'System Administrator',
        };
    }

    /**
     * @return list<Permission>
     */
    public function permissions(): array
    {
        return array_values(array_filter(
            Permission::cases(),
            fn (Permission $permission): bool => in_array($this, $permission->roles(), true),
        ));
    }
}
