<?php

namespace Database\Seeders;

use App\Models\Provider;
use Illuminate\Database\Seeder;

class ProviderSeeder extends Seeder
{
    public function run(): void
    {
        $providers = [
            ['slug' => 'proxyapi', 'name' => 'ProxyAPI'],
            ['slug' => 'polza', 'name' => 'Polza.ai'],
        ];

        foreach ($providers as $sort => $provider) {
            Provider::updateOrCreate(['slug' => $provider['slug']], $provider + ['sort' => $sort]);
        }
    }
}
