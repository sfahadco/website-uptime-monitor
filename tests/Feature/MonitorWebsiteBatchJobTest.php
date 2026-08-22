<?php

namespace Tests\Feature;

use App\Enums\WebsiteStatusEnum;
use App\Jobs\MonitorWebsiteBatchJob;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class MonitorWebsiteBatchJobTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    public function test_marks_a_site_up_on_successful_response(): void
    {
        Http::fake(['*' => Http::response('OK')]);

        $website = Website::factory()->create();

        $this->runJobFor($website);

        $this->assertSame(WebsiteStatusEnum::UP, $website->refresh()->status);
    }

    #[DataProvider('errorStatusCodes')]
    public function test_marks_a_site_down_on_an_error_status_code(int $statusCode): void
    {
        Http::fake(['*' => Http::response('', $statusCode)]);

        $website = Website::factory()->create();

        $this->runJobFor($website);

        $this->assertSame(WebsiteStatusEnum::DOWN, $website->fresh()->status);
    }

    public function test_marks_a_site_down_when_the_request_times_out(): void
    {
        Http::fake(fn () => throw new ConnectionException(
            'cURL error 28: Operation timed out after 10000 milliseconds'
        ));

        $website = Website::factory()->create();

        $this->runJobFor($website);

        $this->assertSame(WebsiteStatusEnum::DOWN, $website->fresh()->status);
    }

    public function test_marks_a_site_down_when_the_host_cannot_be_resolved(): void
    {
        Http::fake(fn () => throw new ConnectionException(
            'cURL error 6: Could not resolve host'
        ));

        $website = Website::factory()->create();

        $this->runJobFor($website);

        $this->assertSame(WebsiteStatusEnum::DOWN, $website->fresh()->status);
    }

    #[DataProvider('checkOutcomes')]
    public function test_stamps_last_checked_at_on_every_check(int $statusCode): void
    {
        Http::fake(['*' => Http::response('', $statusCode)]);

        // Freeze the clock so the stamp can be asserted exactly. Truncated to
        // the second because the column has no sub-second precision, and a
        // microsecond difference would fail an otherwise correct comparison.
        $this->travelTo($now = now()->startOfSecond());

        // Seeded with a stale stamp rather than null, so the assertion proves
        // this check *overwrote* the value instead of merely filling in a blank.
        $website = Website::factory()->create([
            'last_checked_at' => $now->copy()->subDay(),
        ]);

        $this->runJobFor($website);

        $this->assertTrue($now->equalTo($website->fresh()->last_checked_at));
    }

    public function test_continues_checking_the_batch_after_one_website_fails(): void
    {
        Http::fake([
            'broken.test'  => fn () => throw new RuntimeException('Malformed URL'),
            'healthy.test' => Http::response('OK'),
        ]);

        $broken = Website::factory()->create([
            'url'    => 'https://broken.test',
            'status' => WebsiteStatusEnum::UP,
        ]);

        $healthy = Website::factory()->create([
            'url'    => 'https://healthy.test',
            'status' => WebsiteStatusEnum::UNKNOWN,
        ]);

        new MonitorWebsiteBatchJob([$broken->id, $healthy->id])->handle();

        $this->assertSame(WebsiteStatusEnum::DOWN, $broken->fresh()->status);
        $this->assertSame(WebsiteStatusEnum::UP, $healthy->fresh()->status);
    }

    /**
     * @return array<string, array{int}>
     */
    public static function checkOutcomes(): array
    {
        return [
            'site is up'   => [200],
            'site is down' => [503],
        ];
    }

    public static function errorStatusCodes(): array
    {
        return [
            'not found'             => [404],
            'internal server error' => [500],
            'bad gateway'           => [502],
            'service unavailable'   => [503],
        ];
    }

    private function runJobFor(Website $website)
    {
        new MonitorWebsiteBatchJob([$website->id])->handle();
    }
}
