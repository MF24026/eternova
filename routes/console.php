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

// ── Billing crons (Phase 4): the daily heartbeat. Each command is idempotent and skips when
// billing maintenance is enabled. Ordered so charges/dunning run before lifecycle sweeps.
// withoutOverlapping guards against a slow run colliding with the next tick.
Schedule::command('billing:process-recurring-charges')->dailyAt('02:00')->withoutOverlapping();
Schedule::command('billing:retry-dunning')->dailyAt('03:00')->withoutOverlapping();
Schedule::command('billing:suspend-overdue')->dailyAt('04:00')->withoutOverlapping();
Schedule::command('billing:soft-delete-cancelled')->dailyAt('05:00')->withoutOverlapping();
Schedule::command('billing:hard-delete-old')->dailyAt('06:00')->withoutOverlapping();
Schedule::command('billing:reconcile-subscriptions')->dailyAt('07:00')->withoutOverlapping();
Schedule::command('billing:send-trial-reminders')->dailyAt('08:00')->withoutOverlapping();
