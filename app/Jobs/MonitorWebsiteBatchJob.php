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

    public int $timeout;

    // Mark job failed when job is timeout
    public bool $failOnTimeout = true;

    /**
     * Create a new job instance.
     */
    public function __construct(public array $websiteIds)
    {
        $this->onQueue('monitoring');

        $this->timeout = (int) config('monitoring.job_timeout');
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

        $idsByStatus = [];
        $outages = [];

        foreach ($websites as $website) {
            $current = $this->statusFor($responses[(string) $website->id] ?? null);

            $idsByStatus[$current->value][] = $website->id;

            // One alert per outage, not one per failed check.
            if ($current->isNewOutageFrom($website->status)) {
                $outages[] = $website;
            }
        }

        // Statuses are written before any alert goes out, so a mail failure can
        // never leave the batch's results unrecorded.
        $this->persist($idsByStatus);

        foreach ($outages as $website) {
            $this->notifyClient($website);
        }
    }

    private function statusFor(Response|Throwable|null $result): WebsiteStatusEnum
    {
        return $result instanceof Response && $result->successful()
            ? WebsiteStatusEnum::UP
            : WebsiteStatusEnum::DOWN;
    }

    /**
     * One UPDATE per distinct status rather than one per website.
     *
     * A batch only ever produces UP or DOWN, so this is at most two statements
     * regardless of batch size -- where saving each model individually cost one
     * write per site, or thousands of round trips per cycle at full scale.
     *
     * @param  array<string, list<int>>  $idsByStatus
     */
    private function persist(array $idsByStatus): void
    {
        $now = now();

        foreach ($idsByStatus as $status => $ids) {
            Website::query()->whereIn('id', $ids)->update([
                'status' => $status,
                'last_checked_at' => $now,
                // Mass updates bypass the model's timestamp handling, so
                // updated_at has to be set explicitly.
                'updated_at' => $now,
            ]);
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
