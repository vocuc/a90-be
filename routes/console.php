<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('revenue:release')->hourly()->withoutOverlapping();
Schedule::command('generations:sweep-stuck')->everyFiveMinutes()->withoutOverlapping();
