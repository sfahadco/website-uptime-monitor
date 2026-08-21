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
                'https://this-domain-should-not-resolve-xyz.com',  // triggers a down alert
            ],
            'bob@example.com' => [
                'https://vuejs.org',
                'https://httpstat.us',                          // returns an error
            ],
            'john@example.com' => [
                'https://mailpit.axllent.org',
                'https://invalid.website',                          // returns an error
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
