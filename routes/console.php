<?php

use App\Modules\Quotations\Jobs\ExpireQuotationsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ── Quotations: expire past-due quotations daily at midnight UTC
// Runs across ALL tenants (the job uses withoutGlobalScopes internally).
Schedule::job(new ExpireQuotationsJob())->daily();
