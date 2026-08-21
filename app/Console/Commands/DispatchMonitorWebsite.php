<?php

namespace App\Console\Commands;

use App\Jobs\MonitorWebsiteBatchJob;
use App\Models\Website;
use Illuminate\Console\Command;

class DispatchMonitorWebsite extends Command
{
    protected $signature = 'monitor:dispatch';

    protected $description = 'Queue website availability monitor jobs';

    public function handle(): int
    {
        $batches = 0;

        Website::select('id')->chunkById(
            config('monitoring.batch_size'),
            function ($chunk) use (&$batches) {
                MonitorWebsiteBatchJob::dispatch($chunk->pluck('id')->all());
                $batches++;
            }
        );

        $this->info(sprintf('%s jobs queued.', $batches));

        return self::SUCCESS;
    }
}
