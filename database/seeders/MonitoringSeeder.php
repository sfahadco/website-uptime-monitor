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
            [
                'name' => 'Alice Bennett',
                'email' => 'alice@example.com',
                'urls' => [
                    'https://laravel.com',
                    'https://github.com',
                    'https://this-domain-should-not-resolve-xyz.com',
                ],
            ],
            [
                'name' => 'Bob Carter',
                'email' => 'bob@example.com',
                'urls' => [
                    'https://vuejs.org',
                    'https://httpstat.us',
                ],
            ],
            [
                'name' => 'John Dawson',
                'email' => 'john@example.com',
                'urls' => [
                    'https://mailpit.axllent.org',
                    'https://invalid.website',
                ],
            ],
        ];

        foreach ($data as $row) {
            $client = Client::create([
                'name' => $row['name'],
                'email' => $row['email'],
            ]);

            foreach ($row['urls'] as $url) {
                $client->websites()->create(['url' => $url]);
            }
        }
    }
}
