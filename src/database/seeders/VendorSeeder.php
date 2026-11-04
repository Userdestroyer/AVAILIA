<?php

namespace Database\Seeders;

use App\Models\Vendor;
use Illuminate\Database\Seeder;

class VendorSeeder extends Seeder
{
    public function run(): void
    {
        $vendors = [
            ['slug' => 'anthropic', 'name' => 'Anthropic', 'product' => 'Claude'],
            ['slug' => 'google', 'name' => 'Google', 'product' => 'Gemini'],
            ['slug' => 'xai', 'name' => 'xAI', 'product' => 'Grok'],
            ['slug' => 'openai', 'name' => 'OpenAI', 'product' => 'ChatGPT'],
        ];

        foreach ($vendors as $sort => $vendor) {
            Vendor::updateOrCreate(['slug' => $vendor['slug']], $vendor + ['sort' => $sort]);
        }
    }
}
