<?php

namespace App\Console\Commands;

use App\Jobs\MonitorWebsiteBatchJob;
use App\Models\Website;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Queue;

class DispatchMonitorWebsite extends Command
{
    protected $signature = 'monitor:dispatch';

    protected $description = 'Queue website availability monitor jobs';

    public function handle(): int
    {
        $batchSize = (int) config('monitoring.batch_size');
        $dispatchChunk = (int) config('monitoring.dispatch_chunk');

        $pending = [];
        $batches = 0;

        // Only ids, read a chunk at a time, so memory stays flat no matter how
        // big the table gets. Jobs are collected and pushed in groups.
        Website::query()->select('id')->chunkById(
            $batchSize,
            function ($chunk) use (&$pending, &$batches, $dispatchChunk) {
                $pending[] = new MonitorWebsiteBatchJob($chunk->pluck('id')->all());
                $batches++;

                if (count($pending) >= $dispatchChunk) {
                    $this->push($pending);
                    $pending = [];
                }
            }
        );

        // Whatever did not fill a full group.
        $this->push($pending);

        $this->info(sprintf('%s jobs queued.', $batches));

        return self::SUCCESS;
    }

    /**
     * Sends the whole group in one queue round trip instead of one each.
     *
     * @param  list<MonitorWebsiteBatchJob>  $jobs
     */
    private function push(array $jobs): void
    {
        if ($jobs === []) {
            return;
        }

        // A bulk push skips the job dispatcher, so the queue name has to be
        // given here -- the job's own onQueue() is ignored.
        Queue::bulk($jobs, '', 'monitoring');
    }
}
