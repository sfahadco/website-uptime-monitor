<?php

namespace App\Jobs;

use App\Enums\WebsiteStatusEnum;
use App\Models\Website;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
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
            $response = Http::timeout(10)->get($website->url);

            if ($response->successful()) {
                $website->status = WebsiteStatusEnum::UP;
                $website->last_checked_at = now();
                $website->save();
            } else {
                //Todo: use email template instead of string
                // Mail::to($website->client->email)->send();
                Mail::raw('website is down', function ($message) use ($website) {
                    $message->to($website->client->email);
                });
                $website->status = WebsiteStatusEnum::DOWN;
                $website->last_checked_at = now();
                $website->save();
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
