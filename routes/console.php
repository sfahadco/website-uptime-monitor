<?php

use Illuminate\Support\Facades\Schedule;

// This only queues the jobs, it does no HTTP itself. withoutOverlapping stops
// a slow cycle from stacking on top of the next one.
Schedule::command('monitor:dispatch')
    ->everyFifteenMinutes()
    ->withoutOverlapping();
