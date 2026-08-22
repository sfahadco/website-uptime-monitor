<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('monitor:dispatch')
    ->everyFifteenMinutes()
    ->withoutOverlapping();
