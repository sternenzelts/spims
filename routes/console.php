<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Nightly proof that every stock balance still equals its ledger (design §13.3).
Schedule::command('ledger:verify')->dailyAt(Config::string('spims.nightly_at_utc'));
