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

        // firstOrCreate rather than create: bin/setup re-seeds on every run, and
        // both clients.email and (client_id, url) are unique, so a plain insert
        // would fail the second time.
        foreach ($data as $email => $urls) {
            $client = Client::firstOrCreate(['email' => $email]);

            foreach ($urls as $url) {
                $client->websites()->firstOrCreate(['url' => $url]);
            }
        }
    }
}
