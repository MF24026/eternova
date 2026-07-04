<?php

declare(strict_types=1);

namespace App\Modules\Quotations\Providers;

use App\Modules\Quotations\Models\Quotation;
use App\Modules\Quotations\Pdf\PdfException;
use App\Modules\Quotations\Pdf\QuotationPdfRenderer;
use App\Modules\Quotations\Policies\QuotationPolicy;
use App\Modules\Quotations\Repositories\EloquentQuotationRepository;
use App\Modules\Quotations\Repositories\QuotationRepositoryInterface;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * Service provider for the Quotations module.
 *
 * Responsibilities:
 *   - Bind QuotationRepositoryInterface to EloquentQuotationRepository.
 *   - Bind QuotationPdfRenderer to the configured driver (config/pdf.php).
 *     The binding is deferred via a closure so config('pdf.*') is not read
 *     until the interface is actually resolved — mirrors ExpensesServiceProvider.
 *   - Register Gate::policy(Quotation::class, QuotationPolicy::class).
 */
final class QuotationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(QuotationRepositoryInterface::class, EloquentQuotationRepository::class);

        $this->app->bind(QuotationPdfRenderer::class, function (): QuotationPdfRenderer {
            /** @var string $driverName */
            $driverName = config('pdf.renderer', 'dompdf');

            /** @var array<string, class-string<QuotationPdfRenderer>> $drivers */
            $drivers = config('pdf.drivers', []);

            if (! isset($drivers[$driverName])) {
                throw new PdfException(
                    "Unknown PDF renderer driver '{$driverName}'. "
                    .'Available drivers: '.implode(', ', array_keys($drivers)).'. '
                    ."Check the 'renderer' key in config/pdf.php or the PDF_RENDERER env variable.",
                );
            }

            /** @var class-string<QuotationPdfRenderer> $driverClass */
            $driverClass = $drivers[$driverName];

            return $this->app->make($driverClass);
        });
    }

    public function boot(): void
    {
        Gate::policy(Quotation::class, QuotationPolicy::class);
    }
}
