<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/**
 * Seeds the floor, catalogue and printing setup. No users are created: the first login
 * with the default credentials creates the administrator (see InitialSetupController).
 * Demo staff can be added with `php artisan db:seed --class=DemoStaffSeeder`.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PrintingSeeder::class,
            FloorSeeder::class,
            CatalogSeeder::class,
            SetMenuSeeder::class,
            ShiftTemplateSeeder::class,
        ]);
    }
}
