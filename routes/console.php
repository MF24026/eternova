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

// ── Billing crons: the daily lifecycle sweep. Wompi owns recurrence + retries, so we no longer
// self-charge or retry-dunning here; suspend-overdue ages out past_due subscriptions instead.
// Each command is idempotent and skips when billing maintenance is enabled. withoutOverlapping
// guards against a slow run colliding with the next tick.
Schedule::command('billing:suspend-overdue')->dailyAt('04:00')->withoutOverlapping();
Schedule::command('billing:soft-delete-cancelled')->dailyAt('05:00')->withoutOverlapping();
Schedule::command('billing:hard-delete-old')->dailyAt('06:00')->withoutOverlapping();
Schedule::command('billing:reconcile-subscriptions')->dailyAt('07:00')->withoutOverlapping();
Schedule::command('billing:send-trial-reminders')->dailyAt('08:00')->withoutOverlapping();
