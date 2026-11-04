<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Справочники. Сидеры идемпотентны — можно гонять повторно.
     */
    public function run(): void
    {
        $this->call([
            PlanSeeder::class,
            VendorSeeder::class,
            AiModelSeeder::class,
        ]);
    }
}
