<?php

namespace App\Jobs;

use App\Enums\WebsiteStatusEnum;
use App\Mail\WebsiteDownEmail;
use App\Models\Website;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\Pool;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

class MonitorWebsiteBatchJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 60;

    // Mark job failed when job is timeout
    public bool $failOnTimeout = true;

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

        $responses = Http::pool(fn (Pool $pool) => $websites
            ->map(fn (Website $website) => $pool
                ->as((string) $website->id)
                ->timeout(config('monitoring.timeout'))
                ->get($website->url))
            ->all());

        foreach ($websites as $website) {
            $this->record($website, $responses[(string)$website->id] ?? null);
        }
    }

    private function record(Website $website, Response|Throwable|null $result): void
    {
        $current = $result instanceof Response && $result->successful()
            ? WebsiteStatusEnum::UP
            : WebsiteStatusEnum::DOWN;

        $previous = $website->status;

        $website->forceFill([
            'status' => $current,
            'last_checked_at' => now(),
        ])->save();

        // One alert per outage, not one per failed check.
        if ($current->isNewOutageFrom($previous)) {
            $this->notifyClient($website);
        }
    }

    /**
     * Alert the owning client that the website is unreachable.
     *
     * A mail failure must not fail the whole batch, so it is logged and swallowed.
     */
    private function notifyClient(Website $website): void
    {
        $email = $website->client?->email;

        try {
            Mail::to($email)->queue(new WebsiteDownEmail($website));
        } catch (Throwable $exception) {
            Log::error('Failed to send website down alert', [
                'website_id' => $website->id,
                'exception' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * The batch itself gave up -- it exhausted `$tries` or ran past `$timeout`.
     * This says nothing about whether the websites are up: none of them were
     * recorded, so they keep their previous status until the next cycle picks
     * them up fifteen minutes later.
     */
    public function failed(Throwable $exception): void
    {
        Log::error('Monitoring batch failed, websites left unchecked', [
            'website_ids' => $this->websiteIds,
            'exception' => $exception->getMessage(),
        ]);
    }
}
