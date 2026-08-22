<?php

namespace Tests\Feature;

use App\Jobs\MonitorWebsiteBatchJob;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class MonitorDispatchCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_dispatches_jobs_onto_the_monitoring_queue(): void
    {
        Queue::fake();

        Website::factory()->count(3)->create();

        $this->artisan('monitor:dispatch')->assertSuccessful();

        Queue::assertPushedOn('monitoring', MonitorWebsiteBatchJob::class);
    }

    public function test_it_chunks_websites_according_to_the_configured_batch_size(): void
    {
        Queue::fake();
        Config::set('monitoring.batch_size', 25);

        Website::factory()->count(55)->create();

        $this->artisan('monitor:dispatch')->assertSuccessful();

        Queue::assertPushed(MonitorWebsiteBatchJob::class, 3);
    }

    public function test_every_website_id_appears_in_exactly_one_batch(): void
    {
        Queue::fake();
        Config::set('monitoring.batch_size', 25);

        $expectedIds = Website::factory()->count(55)->create()->pluck('id')->all();

        $this->artisan('monitor:dispatch')->assertSuccessful();

        $dispatchedIds = Queue::pushed(MonitorWebsiteBatchJob::class)
            ->flatMap(fn (MonitorWebsiteBatchJob $job) => $job->websiteIds)
            ->all();

        $this->assertCount(
            count($expectedIds),
            $dispatchedIds,
            'A website ID was either dropped from every batch or dispatched in more than one.'
        );

        $this->assertEqualsCanonicalizing($expectedIds, $dispatchedIds);
    }

    public function test_it_dispatches_nothing_when_no_websites_exist(): void
    {
        Queue::fake();

        $this->artisan('monitor:dispatch')->assertSuccessful();

        Queue::assertNothingPushed();
    }
}
