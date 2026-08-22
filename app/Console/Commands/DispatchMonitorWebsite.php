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

        $this->push($pending);

        $this->info(sprintf('%s jobs queued.', $batches));

        return self::SUCCESS;
    }

    /**
     * Push a group of jobs in a single queue round trip.
     *
     * At full scale a cycle produces dozens of batch jobs, and dispatching them
     * one at a time is one Redis round trip each. `Queue::bulk` sends the whole
     * group at once, so the dispatcher's own cost stays negligible next to the
     * fifteen-minute window it has to finish in.
     *
     * @param  list<MonitorWebsiteBatchJob>  $jobs
     */
    private function push(array $jobs): void
    {
        if ($jobs === []) {
            return;
        }

        Queue::bulk($jobs, '', 'monitoring');
    }
}
