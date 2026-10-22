<?php

use Illuminate\Support\Facades\Schedule;

// Reminders, ended ad runs and the daily summary. On the live server (no cron)
// the same work is also started by app traffic and by the ping URL in Settings.
Schedule::command('agency:tick')->everyFiveMinutes()->withoutOverlapping();
