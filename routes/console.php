<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('subscriptions:expire')->dailyAt('00:00')->timezone('Asia/Dushanbe')->withoutOverlapping();
