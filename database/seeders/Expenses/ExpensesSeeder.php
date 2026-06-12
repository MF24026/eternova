<?php

declare(strict_types=1);

namespace Database\Seeders\Expenses;

use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Seeds ~30 realistic expenses per demo tenant spanning the last 5 months,
 * all 5 default categories, and 3 OCR pipeline states.
 *
 * Design decisions — mirrors ReservationsSeeder philosophy exactly:
 *
 *   Raw DB inserts via DB::table():
 *     Bypasses BelongsToTenant::creating() (requires currentTenant in the
 *     service container), Expense observers, and the timestamps() restriction
 *     on created_at. Essential for backdating expense_date and created_at.
 *
 *   Category resolution per tenant:
 *     Categories are NOT hardcoded by ID. They are resolved by (tenant_id, name)
 *     join, so the seeder is safe to run after a full wipe + re-seed of categories
 *     without any id dependency.
 *
 *   OCR state variety (three buckets):
 *     MOST rows: manual entries (ocr_status=none, is_verified=true, receipt_path=null).
 *     A FEW rows: OCR-processed and verified (ocr_status=done, is_verified=true,
 *       receipt_path set to a fake storage key, ocr_data populated).
 *     1-2 rows: OCR drafts awaiting verification (ocr_status=done, is_verified=false,
 *       ocr_data populated). These appear in the E7 verification queue.
 *
 *   Amount realism per category type:
 *     Nómina/Renta: large amounts (50 000–150 000 centavos).
 *     Productos/Operación: mid-range (2 000–40 000 centavos).
 *     Otros: small (500–8 000 centavos).
 *     A couple of null-category rows exercise the "uncategorised" bucket in the UI.
 *
 *   Month distribution:
 *     30 rows distributed across 5 months so the monthly-report chart always has
 *     multi-month bars. Distribution is NOT perfectly even by design — real expense
 *     patterns are lumpy (payroll/rent hit on fixed days; supplies vary).
 *
 *   Idempotency:
 *     Each tenant is skipped entirely if it already has rows in expenses.
 *     Safe to run any number of times from a clean or partial seed.
 */
final class ExpensesSeeder extends Seeder
{
    /**
     * Category names in the seeded order. These MUST match the names written by
     * ExpenseCategoriesSeeder — IDs are resolved per-tenant at runtime.
     *
     * Null entries produce an uncategorised expense (expense_category_id = null).
     * Two nulls out of 30 exercises the "uncategorised" bucket in the list view.
     *
     * Distribution:
     *   operating (Operación)  → 8 rows  (~27 %)
     *   products  (Productos)  → 9 rows  (~30 %)
     *   payroll   (Nómina)     → 5 rows  (~17 %)
     *   rent      (Renta)      → 4 rows  (~13 %)
     *   other     (Otros)      → 2 rows  (~7 %)
     *   null (uncategorised)   → 2 rows  (~7 %)
     *
     * @var list<string|null>
     */
    private const CATEGORY_PLAN = [
        'Operación',   // 1
        'Productos',   // 2
        'Nómina',      // 3
        'Operación',   // 4
        'Productos',   // 5
        'Renta',       // 6
        'Productos',   // 7
        'Operación',   // 8
        'Nómina',      // 9
        'Productos',   // 10
        'Operación',   // 11
        null,          // 12 — uncategorised
        'Productos',   // 13
        'Nómina',      // 14
        'Operación',   // 15
        'Renta',       // 16
        'Productos',   // 17
        'Operación',   // 18
        'Otros',       // 19
        'Nómina',      // 20
        'Productos',   // 21
        'Renta',       // 22
        'Operación',   // 23
        null,          // 24 — uncategorised
        'Nómina',      // 25
        'Productos',   // 26
        'Renta',       // 27
        'Operación',   // 28
        'Otros',       // 29
        'Productos',   // 30
    ];

    /**
     * OCR state plan for the 30 rows.
     *
     * Keyed by 1-based index (seq). Rows not listed default to the "manual" bucket:
     *   ocr_status=none, is_verified=true, receipt_path=null, ocr_data=null.
     *
     * 'ocr_verified'  → OCR ran, staff confirmed: ocr_status=done, is_verified=true.
     * 'ocr_draft'     → OCR ran, NOT yet confirmed: ocr_status=done, is_verified=false.
     *
     * @var array<int, string>
     */
    private const OCR_STATE_PLAN = [
        5  => 'ocr_verified',
        11 => 'ocr_verified',
        17 => 'ocr_verified',
        22 => 'ocr_draft',    // draft #1 — appears in E7 verification queue
        28 => 'ocr_draft',    // draft #2 — appears in E7 verification queue
    ];

    /**
     * Days back from now() for each seq index (1-based).
     *
     * 30 rows distributed across ~5 months (approx 150 days).
     * Several rows cluster in the same month to make the report bars more interesting.
     *
     * @var array<int, int>
     */
    private const DAYS_BACK = [
        1  => 148,   // month -5
        2  => 143,   // month -5
        3  => 138,   // month -5
        4  => 132,   // month -5
        5  => 127,   // month -5
        6  => 120,   // month -4
        7  => 115,   // month -4
        8  => 112,   // month -4
        9  => 108,   // month -4
        10 => 104,   // month -4
        11 =>  95,   // month -3
        12 =>  91,   // month -3
        13 =>  87,   // month -3
        14 =>  83,   // month -3
        15 =>  78,   // month -3
        16 =>  72,   // month -3
        17 =>  65,   // month -2
        18 =>  60,   // month -2
        19 =>  56,   // month -2
        20 =>  52,   // month -2
        21 =>  47,   // month -2
        22 =>  43,   // month -2
        23 =>  35,   // month -1
        24 =>  31,   // month -1
        25 =>  27,   // month -1
        26 =>  22,   // month -1
        27 =>  17,   // month -1
        28 =>  12,   // current month
        29 =>   7,   // current month
        30 =>   2,   // current month
    ];

    /**
     * Vendor names by category type and tenant slug.
     *
     * Provides realistic LatAm business names so the expense list looks lived-in.
     * Rosa Eterna (SV) gets SV-flavoured names; Tatiana (CO) gets CO-flavoured names.
     *
     * @var array<string, array<string, list<string>>>
     */
    private const VENDORS = [
        'rosa-eterna' => [
            'operating' => [
                'Servicios Generales SV',
                'Distribuidora de Papeleria El Sol',
                'Servicios de Limpieza Pro',
                'Suministros de Oficina La Palma',
                'Transportes Rapidos SV',
                'Tecnologia y Redes SA de CV',
            ],
            'products' => [
                'Distribuidora Floral SV',
                'Flores y Plantas del Pacifico',
                'Vivero Central El Salvador',
                'Mayoreo de Flores Centroamerica',
                'Distribuidora de Cintas y Lazos',
                'Envases y Empaques SV',
            ],
            'payroll' => [
                'Ana Garcia (Colaboradora)',
                'Maria Lopez (Colaboradora)',
                'Pedro Hernandez (Colaborador)',
                'Rosa Martinez (Administradora)',
                'Carlos Ramos (Repartidor)',
            ],
            'rent' => [
                'Inmobiliaria del Centro SV',
                'Arrendamientos El Rosal',
                'Administracion Plaza Central',
            ],
            'other' => [
                'Gastos varios',
                'Caja chica',
                'Varios proveedores',
            ],
        ],
        'tatiana' => [
            'operating' => [
                'Servicios Generales Bogota',
                'Papeleria y Suministros La 28',
                'Aseo Profesional Colombia',
                'Suministros de Oficina Exito',
                'Transportes Urbanos CO',
                'Soluciones Digitales Colombia',
            ],
            'products' => [
                'Distribuidora Florista Colombia',
                'Flores Frescas de la Sabana',
                'Viveros del Altiplano',
                'Peluches y Regalos Mayoreo CO',
                'Distribuidora de Empaques CO',
                'Accesorios y Decoracion Bogota',
            ],
            'payroll' => [
                'Valentina Torres (Colaboradora)',
                'Daniela Ramos (Colaboradora)',
                'Sebastian Moreno (Colaborador)',
                'Adriana Perez (Administradora)',
                'Julian Castro (Mensajero)',
            ],
            'rent' => [
                'Inmobiliaria Chapinero CO',
                'Arrendamientos Centro Bogota',
                'Administracion Centro Comercial CO',
            ],
            'other' => [
                'Gastos varios',
                'Caja menor',
                'Varios proveedores',
            ],
        ],
    ];

    /**
     * Amount ranges in centavos by category type.
     *
     * Nómina and Renta are large fixed-cost items.
     * Productos and Operación are variable mid-range.
     * Otros and uncategorised tend to be petty cash.
     *
     * @var array<string, array{min: int, max: int}>
     */
    private const AMOUNT_RANGES = [
        'payroll'   => ['min' =>  50_000, 'max' => 150_000],
        'rent'      => ['min' =>  60_000, 'max' => 120_000],
        'products'  => ['min' =>   2_000, 'max' =>  40_000],
        'operating' => ['min' =>   1_500, 'max' =>  25_000],
        'other'     => ['min' =>     500, 'max' =>   8_000],
        'null'      => ['min' =>     800, 'max' =>  12_000],   // uncategorised
    ];

    /**
     * Category type slug indexed by Spanish display name.
     * Matches what ExpenseCategoriesSeeder writes into expense_categories.type.
     *
     * @var array<string, string>
     */
    private const CATEGORY_TYPE_BY_NAME = [
        'Operación' => 'operating',
        'Productos' => 'products',
        'Nómina'    => 'payroll',
        'Renta'     => 'rent',
        'Otros'     => 'other',
    ];

    public function run(): void
    {
        foreach (Tenant::all() as $tenant) {
            $this->seedForTenant($tenant);
        }
    }

    private function seedForTenant(Tenant $tenant): void
    {
        $alreadySeeded = DB::table('expenses')
            ->where('tenant_id', $tenant->id)
            ->exists();

        if ($alreadySeeded) {
            $this->command->info(
                "ExpensesSeeder: expenses already exist for {$tenant->slug}, skipping.",
            );

            return;
        }

        $branch = $this->resolveMainBranch($tenant);

        if ($branch === null) {
            $this->command->warn(
                "ExpensesSeeder: no main branch for tenant {$tenant->slug}, skipping.",
            );

            return;
        }

        $staffUserIds = $this->resolveStaffUserIds($tenant);

        if ($staffUserIds->isEmpty()) {
            $this->command->warn(
                "ExpensesSeeder: no staff users for tenant {$tenant->slug}, skipping.",
            );

            return;
        }

        // Resolve categories once per tenant — avoids N+1 on each row.
        $categoryIdByName = $this->resolveCategoryIds($tenant);

        $rows = [];

        foreach (self::CATEGORY_PLAN as $seqIndex => $categoryName) {
            $seq         = $seqIndex + 1;
            $daysBack    = self::DAYS_BACK[$seq];
            $hoursBack   = random_int(8, 20);  // within business hours
            $expenseDate = Carbon::now()->subDays($daysBack)->toDateString();
            $createdAt   = Carbon::now()->subDays($daysBack)->subHours($hoursBack);
            $updatedAt   = $createdAt->copy()->addMinutes(random_int(0, 30));

            $categoryId   = ($categoryName !== null) ? ($categoryIdByName[$categoryName] ?? null) : null;
            $categoryType = ($categoryName !== null) ? (self::CATEGORY_TYPE_BY_NAME[$categoryName] ?? 'other') : 'null';

            [$amountCents] = $this->resolveAmount($categoryType, $seq);

            // branch_id: mostly the main branch, a few null (head-office expenses).
            $branchId = ($seq % 7 === 0) ? null : $branch->id;

            $createdBy = $staffUserIds->isNotEmpty()
                ? ($seq % 2 === 0 ? $staffUserIds->first() : $staffUserIds->last())
                : null;

            $ocrBucket   = self::OCR_STATE_PLAN[$seq] ?? 'manual';
            $description = $this->buildDescription($tenant, $categoryName, $seq);
            $vendor      = $this->resolveVendor($tenant, $categoryName, $categoryType, $seq);
            $notes       = $this->maybeNotes($seq, $categoryType);

            $rows[] = $this->buildRow(
                tenantId: $tenant->id,
                branchId: $branchId,
                categoryId: $categoryId,
                description: $description,
                amountCents: $amountCents,
                expenseDate: $expenseDate,
                vendor: $vendor,
                paymentMethod: $this->randomPaymentMethod($seq),
                ocrBucket: $ocrBucket,
                seq: $seq,
                createdBy: $createdBy,
                notes: $notes,
                createdAt: $createdAt,
                updatedAt: $updatedAt,
            );
        }

        DB::table('expenses')->insert($rows);

        $monthsWithData = $this->countDistinctMonths($rows);

        $this->command->info(
            "ExpensesSeeder: {$tenant->slug} — "
            . count($rows) . " expenses seeded "
            . "across {$monthsWithData} months "
            . "(OCR-done-verified=" . count(array_filter($rows, fn($r) => $r['ocr_status'] === 'done' && $r['is_verified'])) . ", "
            . "OCR-drafts=" . count(array_filter($rows, fn($r) => $r['ocr_status'] === 'done' && ! $r['is_verified'])) . ").",
        );
    }

    // -------------------------------------------------------------------------
    // Row builder
    // -------------------------------------------------------------------------

    /**
     * Build a single expenses row.
     *
     * ocr_bucket controls the three pipeline states:
     *   'manual'       → no receipt, verified by human directly.
     *   'ocr_verified' → OCR ran + staff confirmed (has receipt_path + ocr_data).
     *   'ocr_draft'    → OCR ran but NOT yet confirmed (is_verified=false).
     *
     * @return array<string, mixed>
     */
    private function buildRow(
        string $tenantId,
        ?string $branchId,
        ?int $categoryId,
        string $description,
        int $amountCents,
        string $expenseDate,
        ?string $vendor,
        ?string $paymentMethod,
        string $ocrBucket,
        int $seq,
        ?int $createdBy,
        ?string $notes,
        Carbon $createdAt,
        Carbon $updatedAt,
    ): array {
        [$ocrStatus, $isVerified, $receiptPath, $ocrData] = $this->resolveOcrFields(
            bucket: $ocrBucket,
            tenantId: $tenantId,
            seq: $seq,
            vendor: $vendor,
            amountCents: $amountCents,
            expenseDate: $expenseDate,
        );

        return [
            'tenant_id'           => $tenantId,
            'branch_id'           => $branchId,
            'expense_category_id' => $categoryId,
            'description'         => $description,
            'amount_cents'        => $amountCents,
            'expense_date'        => $expenseDate,
            'vendor'              => $vendor,
            'payment_method'      => $paymentMethod,
            'receipt_path'        => $receiptPath,
            'ocr_status'          => $ocrStatus,
            'ocr_data'            => $ocrData,
            'is_verified'         => $isVerified,
            'notes'               => $notes,
            'created_by'          => $createdBy,
            'created_at'          => $createdAt->toDateTimeString(),
            'updated_at'          => $updatedAt->toDateTimeString(),
            'deleted_at'          => null,
        ];
    }

    // -------------------------------------------------------------------------
    // OCR fields resolver
    // -------------------------------------------------------------------------

    /**
     * Resolve the four OCR-related columns from the given bucket label.
     *
     * Returns [$ocrStatus, $isVerified, $receiptPath, $ocrDataJson].
     *
     * receipt_path uses a fake storage key — the file need not exist on disk for
     * the list and monthly-report views to render correctly. The E7 verification
     * UI will show an "image not found" state for these demo rows, which is
     * acceptable and realistic (demo data, not real uploads).
     *
     * @return array{string, bool, string|null, string|null}
     */
    private function resolveOcrFields(
        string $bucket,
        string $tenantId,
        int $seq,
        ?string $vendor,
        int $amountCents,
        string $expenseDate,
    ): array {
        if ($bucket === 'manual') {
            return ['none', true, null, null];
        }

        $isVerified  = ($bucket === 'ocr_verified');
        $receiptPath = "tenants/{$tenantId}/receipts/demo-{$seq}.jpg";

        // Build realistic ocr_data as the job would produce.
        // confidence: OCR-verified rows show higher confidence; drafts slightly lower.
        $confidence = $isVerified
            ? round(random_int(82, 97) / 100, 2)
            : round(random_int(55, 75) / 100, 2);

        $ocrData = json_encode([
            'vendor'       => $vendor ?? 'Proveedor desconocido',
            'amount_cents' => $amountCents,
            'date'         => $expenseDate,
            'raw_text'     => $this->buildFakeRawText($vendor, $amountCents, $expenseDate),
            'confidence'   => $confidence,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        return ['done', $isVerified, $receiptPath, $ocrData];
    }

    /**
     * Build a plausible raw OCR text excerpt (mimics Tesseract output on a receipt).
     * This populates ocr_data.raw_text so the E7 verification UI can show it.
     */
    private function buildFakeRawText(?string $vendor, int $amountCents, string $expenseDate): string
    {
        $amount = number_format($amountCents / 100, 2);

        return implode("\n", [
            strtoupper($vendor ?? 'PROVEEDOR'),
            'FACTURA / RECIBO',
            "Fecha: {$expenseDate}",
            "Total a pagar: \${$amount}",
            'Gracias por su compra',
        ]);
    }

    // -------------------------------------------------------------------------
    // Amount helper
    // -------------------------------------------------------------------------

    /**
     * Pick a random amount in centavos for the given category type.
     * Returns [amountCents] — tuple for forward-compatible expansion.
     *
     * @return array{int}
     */
    private function resolveAmount(string $categoryType, int $seq): array
    {
        $range  = self::AMOUNT_RANGES[$categoryType] ?? self::AMOUNT_RANGES['other'];
        $amount = random_int($range['min'], $range['max']);

        return [$amount];
    }

    // -------------------------------------------------------------------------
    // Description / vendor / notes helpers
    // -------------------------------------------------------------------------

    /**
     * Build a realistic single-line description for the expense row.
     * The description appears as the primary label in the expense list view.
     */
    private function buildDescription(Tenant $tenant, ?string $categoryName, int $seq): string
    {
        $templates = match ($categoryName) {
            'Nómina'    => [
                'Pago de nomina quincenal',
                'Salario mensual colaboradores',
                'Pago semanal de personal',
                'Remuneracion colaboradora',
            ],
            'Renta'     => [
                'Pago de renta mensual del local',
                'Alquiler del local comercial',
                'Renta de bodega',
                'Pago de arriendo mensual',
            ],
            'Productos' => match ($tenant->slug) {
                'rosa-eterna' => [
                    'Compra de flores y follaje',
                    'Adquisicion de flores frescas',
                    'Compra de materiales florales',
                    'Insumos: cintas, lazos y papel',
                    'Flores de temporada — mayoreo',
                ],
                default       => [
                    'Compra de peluches y accesorios',
                    'Adquisicion de regalos variados',
                    'Insumos para canastas y arreglos',
                    'Materiales de empaque y decoracion',
                    'Inventario: productos de temporada',
                ],
            },
            'Operación' => [
                'Servicios de limpieza del local',
                'Papeleria y utiles de oficina',
                'Servicios de internet y telefonia',
                'Mantenimiento de equipo',
                'Transporte y envios',
                'Publicidad en redes sociales',
            ],
            'Otros'     => [
                'Gasto varios de caja chica',
                'Imprevistos del mes',
                'Gastos menores sin categoria',
            ],
            default     => [     // uncategorised
                'Gasto pendiente de clasificar',
                'Compra sin categorizar',
            ],
        };

        return $templates[$seq % count($templates)];
    }

    /**
     * Resolve a realistic vendor name for the given tenant and category.
     * Staff names are used for payroll rows (the vendor IS the employee).
     */
    private function resolveVendor(
        Tenant $tenant,
        ?string $categoryName,
        string $categoryType,
        int $seq,
    ): ?string {
        if ($categoryName === null) {
            return null;
        }

        $pool = self::VENDORS[$tenant->slug][$categoryType]
            ?? self::VENDORS['rosa-eterna'][$categoryType]
            ?? ['Proveedor generico'];

        return $pool[$seq % count($pool)];
    }

    /**
     * Return an admin/staff note on ~every 4th expense; null otherwise.
     * Exercises the nullable notes field in list and detail views.
     */
    private function maybeNotes(int $seq, string $categoryType): ?string
    {
        if ($seq % 4 !== 0) {
            return null;
        }

        $byType = match ($categoryType) {
            'payroll'   => 'Incluye bonificacion por productividad',
            'rent'      => 'Pago puntual — sin recargo por mora',
            'products'  => 'Solicitar factura fiscal en proxima compra',
            'operating' => 'Revisar si aplica deduccion de impuestos',
            default     => 'Verificar con contabilidad',
        };

        return $byType;
    }

    // -------------------------------------------------------------------------
    // Payment method helper
    // -------------------------------------------------------------------------

    /**
     * Realistic payment-method mix for LatAm small businesses.
     * Cash-heavy, transfers growing, card occasional.
     */
    private function randomPaymentMethod(int $seq): string
    {
        return match ($seq % 10) {
            0, 1, 2, 3 => 'cash',
            4, 5, 6    => 'transfer',
            7          => 'card',
            default    => 'other',
        };
    }

    // -------------------------------------------------------------------------
    // Data resolution helpers
    // -------------------------------------------------------------------------

    private function resolveMainBranch(Tenant $tenant): ?Branch
    {
        return Branch::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('is_main', true)
            ->first();
    }

    /**
     * Resolve staff and owner user IDs for the tenant.
     *
     * @return Collection<int, int>
     */
    private function resolveStaffUserIds(Tenant $tenant): Collection
    {
        return DB::table('tenant_users')
            ->where('tenant_id', $tenant->id)
            ->whereIn('role', ['owner', 'admin', 'staff'])
            ->pluck('user_id');
    }

    /**
     * Resolve the tenant's expense_category rows and index them by name.
     * Relies on ExpenseCategoriesSeeder having run first (DatabaseSeeder order guarantees this).
     *
     * @return array<string, int>  name → id
     */
    private function resolveCategoryIds(Tenant $tenant): array
    {
        return DB::table('expense_categories')
            ->where('tenant_id', $tenant->id)
            ->pluck('id', 'name')
            ->all();
    }

    // -------------------------------------------------------------------------
    // Reporting helper (for the console summary line only)
    // -------------------------------------------------------------------------

    /**
     * Count distinct calendar months represented by the seeded expense_date values.
     *
     * @param  list<array<string, mixed>>  $rows
     */
    private function countDistinctMonths(array $rows): int
    {
        $months = [];

        foreach ($rows as $row) {
            $month = Carbon::parse((string) $row['expense_date'])->format('Y-m');
            $months[$month] = true;
        }

        return count($months);
    }
}
