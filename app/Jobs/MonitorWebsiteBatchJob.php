<?php

namespace App\Jobs;

use App\Enums\WebsiteStatusEnum;
use App\Models\Website;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class MonitorWebsiteBatchJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 60;

    /**
     * Create a new job instance.
     */
    public function __construct(public array $websiteIds)
    {
        $this->onQueue('monitoring');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $websites = Website::with('client')->whereIn('id', $this->websiteIds)->get();

        if ($websites->isEmpty()) {
            return;
        }

        foreach ($websites as $website) {
            $response = Http::timeout(config(10))->get($website->url);

            if ($response->successful()) {
                $website->status = WebsiteStatusEnum::UP;
                $website->lastCheckedAt = now();
                $website->save();
            } else {
                // Todo: send alert
            }
        }
    }

    public function failed(Throwable $exception): void
    {
        Log::error('Website is down', [
            'website_id' => $this->websiteIds,
            'exception' => $exception->getMessage(),
        ]);
    }
}
