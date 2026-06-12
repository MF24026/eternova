<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Providers;

use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Ocr\OcrDriverInterface;
use App\Modules\Expenses\Ocr\OcrException;
use App\Modules\Expenses\Policies\ExpensePolicy;
use App\Modules\Expenses\Repositories\EloquentExpenseRepository;
use App\Modules\Expenses\Repositories\ExpenseRepositoryInterface;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the Expenses module.
 *
 * Responsibilities:
 *   - Bind ExpenseRepositoryInterface to EloquentExpenseRepository (S6-E4).
 *   - Bind OcrDriverInterface to the configured driver class (S6-E2).
 *     The binding is deferred via a closure so that config('ocr.*') is
 *     not read until the interface is actually resolved (avoids boot-time
 *     config-cache issues in tests that swap the config).
 *   - Register Gate::policy(Expense::class, ExpensePolicy::class) so that
 *     $this->authorize('create', Expense::class) in controllers resolves
 *     to ExpensePolicy::create().
 */
final class ExpensesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(ExpenseRepositoryInterface::class, EloquentExpenseRepository::class);

        $this->app->bind(OcrDriverInterface::class, function (): OcrDriverInterface {
            /** @var string $driverName */
            $driverName = config('ocr.driver', 'tesseract');

            /** @var array<string, class-string<OcrDriverInterface>> $drivers */
            $drivers = config('ocr.drivers', []);

            if (! isset($drivers[$driverName])) {
                throw new OcrException(
                    "Unknown OCR driver '{$driverName}'. "
                    . 'Available drivers: ' . implode(', ', array_keys($drivers)) . '. '
                    . "Check the 'driver' key in config/ocr.php or the OCR_DRIVER env variable.",
                );
            }

            /** @var class-string<OcrDriverInterface> $driverClass */
            $driverClass = $drivers[$driverName];

            return $this->app->make($driverClass);
        });
    }

    public function boot(): void
    {
        Gate::policy(Expense::class, ExpensePolicy::class);
    }
}
