<?php

namespace Database\Seeders;

use App\Models\Client;
use Illuminate\Database\Seeder;

class MonitoringSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $data = [
            'alice@example.com' => [
                'https://laravel.com',
                'https://github.com',
                'https://this-domain-should-not-resolve-xyz.com',
            ],
            'bob@example.com' => [
                'https://vuejs.org',
                'https://httpstat.us',
            ],
            'john@example.com' => [
                'https://mailpit.axllent.org',
                'https://invalid.website',
            ],
        ];

        foreach ($data as $email => $urls) {
            $client = Client::create(['email' => $email]);

            foreach ($urls as $url) {
                $client->websites()->create(['url' => $url]);
            }
        }
    }
}
