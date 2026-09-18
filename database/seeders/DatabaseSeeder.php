<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Install defaults every company gets (provisioning checklist step 3). Never seeds users,
     * company details or sample data: those are provisioning steps of their own.
     */
    public function run(): void
    {
        $this->call([
            RolesAndPermissionsSeeder::class,
        ]);
    }
}
