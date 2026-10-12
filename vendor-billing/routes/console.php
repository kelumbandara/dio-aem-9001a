<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('billing:invoice')->dailyAt('06:00')->timezone('Asia/Colombo');
Schedule::command('billing:remind')->dailyAt('08:00')->timezone('Asia/Colombo');
