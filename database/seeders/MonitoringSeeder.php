<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Website;
use Illuminate\Database\Seeder;

/**
 * Seeds the brief's target scale: hundreds of clients with up to ten websites
 * each, plus a handful of hand-written clients pointing at real URLs.
 *
 * Two kinds of data, because they answer different questions. The demo clients
 * prove the monitor works -- their URLs genuinely resolve (or genuinely do
 * not), so the first cycle produces real UP and DOWN rows and a real alert
 * email. The generated clients prove it works *at volume* -- they exist to put
 * a realistic number of rows behind the dispatcher, the batch jobs and the
 * client list.
 */
class MonitoringSeeder extends Seeder
{
    private const CHUNK = 250;

    private const WEBSITE_CHUNK = 500;

    /**
     * Real URLs, so a reviewer sees genuine UP and DOWN results rather than a
     * table of uniform failures. The unresolvable hosts are deliberate: they
     * exercise the outage path and put a real alert in Mailpit on cycle one.
     */
    private const DEMO_CLIENTS = [
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

    public function run(): void
    {
        $this->seedDemoClients();
        $this->seedGeneratedClients();
    }

    /**
     * Three clients, seven websites, written one row at a time.
     *
     * `firstOrCreate` rather than `create`: bin/setup re-seeds on every run,
     * and both clients.email and (client_id, url) are unique, so a plain
     * insert would fail the second time. At seven rows the extra queries do
     * not matter and the readability does.
     */
    private function seedDemoClients(): void
    {
        foreach (self::DEMO_CLIENTS as $email => $urls) {
            $client = Client::firstOrCreate(['email' => $email]);

            foreach ($urls as $url) {
                $client->websites()->firstOrCreate(['url' => $url]);
            }
        }
    }

    /**
     * Hundreds of clients, written with bulk inserts.
     *
     * Everything here is derived from the client's index rather than drawn
     * from Faker, which is what makes re-seeding a no-op: the same run
     * produces the same emails and the same URLs, so `insertOrIgnore` collides
     * with the existing rows and writes nothing. Random data would instead add
     * a fresh set of hundreds of clients on every `bin/setup`.
     */
    private function seedGeneratedClients(): void
    {
        $total = (int) config('monitoring.seed.clients');
        $maxWebsites = (int) config('monitoring.seed.max_websites_per_client');

        if ($total < 1 || $maxWebsites < 1) {
            return;
        }

        $now = now();
        $websiteCount = 0;

        foreach (array_chunk(range(1, $total), self::CHUNK) as $indexes) {
            $emails = array_map($this->emailFor(...), $indexes);

            Client::insertOrIgnore(array_map(
                fn (string $email) => ['email' => $email, 'created_at' => $now, 'updated_at' => $now],
                $emails,
            ));

            $clientIds = Client::query()->whereIn('email', $emails)->pluck('id', 'email');

            $rows = [];

            foreach ($indexes as $index) {
                $clientId = $clientIds[$this->emailFor($index)];

                for ($n = 1; $n <= $this->websiteCountFor($index, $maxWebsites); $n++) {
                    $rows[] = [
                        'client_id' => $clientId,
                        // .example.com is reserved by RFC 2606, so these hosts
                        // can never resolve to somebody's real server. The
                        // monitor will mark every one of them down, which is
                        // the intended outcome: nothing here should generate
                        // traffic to a third party.
                        'url' => sprintf('https://client-%04d-site-%02d.example.com', $index, $n),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            // status and last_checked_at are left out so the column defaults
            // apply -- these sites are genuinely unknown until a cycle runs.
            foreach (array_chunk($rows, self::WEBSITE_CHUNK) as $batch) {
                Website::insertOrIgnore($batch);
            }

            $websiteCount += count($rows);
        }

        $this->command?->info(sprintf(
            '%d generated clients with %d websites (up to %d each).',
            $total,
            $websiteCount,
            $maxWebsites,
        ));
    }

    private function emailFor(int $index): string
    {
        return sprintf('client-%04d@example.com', $index);
    }

    /**
     * Spreads clients evenly across 1..$max websites instead of giving every
     * client the maximum, so the list view and the batching logic both see a
     * realistic mix of small and large clients.
     */
    private function websiteCountFor(int $index, int $max): int
    {
        return (($index - 1) % $max) + 1;
    }
}
