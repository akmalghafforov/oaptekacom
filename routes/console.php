<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('subscriptions:expire')->dailyAt('00:00')->timezone('Asia/Dushanbe')->withoutOverlapping();

Schedule::command('gmail-imports:sync')->everyFiveMinutes()->withoutOverlapping();
Schedule::command('gmail-imports:cleanup')->daily()->withoutOverlapping();
