<?php

namespace Database\Seeders;

use App\Models\Vendor;
use Illuminate\Database\Seeder;

class VendorSeeder extends Seeder
{
    public function run(): void
    {
        $vendors = [
            'openai' => 'OpenAI',
            'anthropic' => 'Anthropic',
            'google' => 'Google',
            'xai' => 'xAI',
        ];

        foreach (array_keys($vendors) as $sort => $slug) {
            Vendor::updateOrCreate(['slug' => $slug], ['name' => $vendors[$slug], 'sort' => $sort]);
        }
    }
}
