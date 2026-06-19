<?php

declare(strict_types=1);

namespace App\Modules\Billing\Console\Commands;

use App\Modules\Billing\Support\BillingMaintenance;
use Illuminate\Console\Command;

/**
 * Toggle / inspect billing maintenance mode. When enabled, the billing crons skip their run
 * (so we never charge/suspend/delete mid-migration or during a gateway incident).
 *
 *   php artisan billing:maintenance enable
 *   php artisan billing:maintenance disable
 *   php artisan billing:maintenance status --format=json
 */
final class BillingMaintenanceCommand extends Command
{
    protected $signature = 'billing:maintenance {action : enable|disable|status} {--format=text : text|json}';

    protected $description = 'Enable, disable, or report billing maintenance mode.';

    public function handle(): int
    {
        $action = (string) $this->argument('action');

        return match ($action) {
            'enable' => $this->setMode(true),
            'disable' => $this->setMode(false),
            'status' => $this->reportStatus(),
            default => $this->invalidAction($action),
        };
    }

    private function setMode(bool $enable): int
    {
        $enable ? BillingMaintenance::enable() : BillingMaintenance::disable();
        $this->info('Billing maintenance '.($enable ? 'ENABLED' : 'DISABLED').'.');

        return self::SUCCESS;
    }

    private function reportStatus(): int
    {
        $enabled = BillingMaintenance::isEnabled();

        if ($this->option('format') === 'json') {
            $this->line(json_encode(['maintenance' => $enabled], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        $this->info('Billing maintenance is '.($enabled ? 'ENABLED' : 'DISABLED').'.');

        return self::SUCCESS;
    }

    private function invalidAction(string $action): int
    {
        $this->error("Unknown action [{$action}]. Use enable|disable|status.");

        return self::INVALID;
    }
}
