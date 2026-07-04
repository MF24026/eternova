<?php

declare(strict_types=1);

namespace Tests\Feature\Expenses;

use App\Models\User;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Models\ExpenseCategory;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\ActingAsTenantMember;
use Tests\TestCase;

/**
 * Feature tests for the Expense CRUD API (S6-E4).
 *
 * Verifies:
 *   - index returns paginated expenses scoped to the current tenant only
 *   - index period_total_cents reflects the filtered set
 *   - index filters (category, date_from/to, month, ocr_status, is_verified, search) work
 *   - show returns full detail; cross-tenant id → 404
 *   - store (manual) creates verified expense; 201
 *   - update edits fields; PATCH with is_verified=true confirms a draft
 *   - destroy soft-deletes the record; receipt file is removed (best-effort)
 *   - auth gates: 401 unauthenticated, 403 customer role, 403 staff on delete
 *   - multi-tenant isolation: cross-tenant expense ids → 404
 */
final class ExpenseApiTest extends TestCase
{
    use ActingAsTenantMember;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('expenses.receipt_disk'));
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @return array{tenant: Tenant, branch: Branch, owner: User}
     */
    private function setupTenant(): array
    {
        $tenant = Tenant::factory()->create();
        $branch = Branch::factory()->forTenant($tenant)->create(['is_main' => true]);
        $owner = User::factory()->forTenant($tenant, role: 'owner')->create();

        app()->instance('currentTenant', $tenant);

        return compact('tenant', 'branch', 'owner');
    }

    // ── Auth gates ────────────────────────────────────────────────────────────

    public function test_unauthenticated_request_returns_401(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();

        $this->getJson($this->tenantUrl($tenant, 'api/v1/expenses'))
            ->assertStatus(401);
    }

    public function test_customer_role_cannot_list_expenses(): void
    {
        ['tenant' => $tenant] = $this->setupTenant();
        $customer = User::factory()->forTenant($tenant, role: 'customer')->create();

        $this->tenantGetJson($tenant, $customer, '/api/v1/expenses')
            ->assertStatus(403);
    }

    public function test_staff_cannot_delete_expense(): void
    {
        ['tenant' => $tenant, 'branch' => $branch] = $this->setupTenant();
        $staff = User::factory()->forTenant($tenant, role: 'staff')->create();
        $expense = Expense::factory()->forBranch($branch)->verified()->create();

        $this->tenantDeleteJson($tenant, $staff, "/api/v1/expenses/{$expense->id}")
            ->assertStatus(403);
    }

    // ── index: pagination + tenant isolation ─────────────────────────────────

    public function test_index_returns_paginated_expenses_for_current_tenant_only(): void
    {
        ['tenant' => $tenantA, 'branch' => $branchA, 'owner' => $ownerA] = $this->setupTenant();

        Expense::factory()->forBranch($branchA)->count(2)->create();

        ['tenant' => $tenantB, 'branch' => $branchB] = $this->setupTenant();
        Expense::factory()->forBranch($branchB)->count(3)->create();

        // Restore tenant A context for the request
        app()->instance('currentTenant', $tenantA);

        $response = $this->tenantGetJson($tenantA, $ownerA, '/api/v1/expenses')
            ->assertOk()
            ->assertJsonStructure([
                'data',
                'links' => ['first', 'last', 'prev', 'next'],
                'meta' => ['current_page', 'per_page', 'total', 'tenant_id'],
                'period_total_cents',
            ]);

        $this->assertCount(2, $response->json('data'));
    }

    public function test_index_includes_period_total_cents_for_filtered_set(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner] = $this->setupTenant();

        Expense::factory()->forBranch($branch)->create([
            'amount_cents' => 5000,
            'expense_date' => '2026-01-10',
        ]);
        Expense::factory()->forBranch($branch)->create([
            'amount_cents' => 3000,
            'expense_date' => '2026-01-20',
        ]);
        // Outside the month filter — must not be included in total
        Expense::factory()->forBranch($branch)->create([
            'amount_cents' => 9999,
            'expense_date' => '2026-02-05',
        ]);

        $response = $this->tenantGetJson($tenant, $owner, '/api/v1/expenses?month=2026-01')
            ->assertOk();

        $this->assertSame(8000, $response->json('period_total_cents'));
        $this->assertCount(2, $response->json('data'));
    }

    // ── index: filters ────────────────────────────────────────────────────────

    public function test_index_filter_by_expense_category_id(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner] = $this->setupTenant();

        $catA = ExpenseCategory::factory()->forTenant($tenant)->create();
        $catB = ExpenseCategory::factory()->forTenant($tenant)->create();

        Expense::factory()->forBranch($branch)->forCategory($catA)->create();
        Expense::factory()->forBranch($branch)->forCategory($catB)->create();

        $response = $this->tenantGetJson($tenant, $owner, "/api/v1/expenses?expense_category_id={$catA->id}")
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame($catA->id, $response->json('data.0.category.id'));
    }

    public function test_index_filter_by_date_from_and_date_to(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner] = $this->setupTenant();

        Expense::factory()->forBranch($branch)->create(['expense_date' => '2026-03-01']);
        Expense::factory()->forBranch($branch)->create(['expense_date' => '2026-03-15']);
        Expense::factory()->forBranch($branch)->create(['expense_date' => '2026-04-01']); // outside

        $response = $this->tenantGetJson($tenant, $owner, '/api/v1/expenses?date_from=2026-03-01&date_to=2026-03-31')
            ->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    public function test_index_filter_by_month(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner] = $this->setupTenant();

        Expense::factory()->forBranch($branch)->create(['expense_date' => '2026-05-05']);
        Expense::factory()->forBranch($branch)->create(['expense_date' => '2026-05-20']);
        Expense::factory()->forBranch($branch)->create(['expense_date' => '2026-06-01']); // outside

        $response = $this->tenantGetJson($tenant, $owner, '/api/v1/expenses?month=2026-05')
            ->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    public function test_index_filter_by_ocr_status(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner] = $this->setupTenant();

        Expense::factory()->forBranch($branch)->draft()->create();   // ocr_status=done
        Expense::factory()->forBranch($branch)->verified()->create(); // ocr_status=none

        $response = $this->tenantGetJson($tenant, $owner, '/api/v1/expenses?ocr_status=done')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('done', $response->json('data.0.ocr_status'));
    }

    public function test_index_filter_by_is_verified_false_returns_drafts(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner] = $this->setupTenant();

        Expense::factory()->forBranch($branch)->draft()->create();    // is_verified=false
        Expense::factory()->forBranch($branch)->verified()->create(); // is_verified=true

        $response = $this->tenantGetJson($tenant, $owner, '/api/v1/expenses?is_verified=0')
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertFalse($response->json('data.0.is_verified'));
    }

    public function test_index_filter_by_search_matches_vendor_and_description(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner] = $this->setupTenant();

        Expense::factory()->forBranch($branch)->create(['vendor' => 'Flores del Norte']);
        Expense::factory()->forBranch($branch)->create(['description' => 'Compra flores de temporada']);
        Expense::factory()->forBranch($branch)->create(['vendor' => 'Papeleria Central', 'description' => 'Papel bond']);

        $response = $this->tenantGetJson($tenant, $owner, '/api/v1/expenses?search=flores')
            ->assertOk();

        $this->assertCount(2, $response->json('data'));
    }

    // ── show ──────────────────────────────────────────────────────────────────

    public function test_show_returns_full_expense_detail(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner] = $this->setupTenant();
        $category = ExpenseCategory::factory()->forTenant($tenant)->create();

        $expense = Expense::factory()
            ->forBranch($branch)
            ->forCategory($category)
            ->createdBy($owner)
            ->create(['amount_cents' => 12500, 'vendor' => 'Proveedor Demo']);

        $response = $this->tenantGetJson($tenant, $owner, "/api/v1/expenses/{$expense->id}")
            ->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'id', 'description', 'amount_cents', 'expense_date', 'vendor',
                    'payment_method', 'ocr_status', 'is_verified', 'ocr_data',
                    'receipt_url', 'notes', 'created_at', 'updated_at',
                    'category' => ['id', 'name', 'type'],
                    'branch' => ['id', 'name'],
                    'creator' => ['id', 'name'],
                ],
            ]);

        $this->assertSame(12500, $response->json('data.amount_cents'));
        $this->assertSame('Proveedor Demo', $response->json('data.vendor'));
    }

    public function test_show_cross_tenant_expense_id_returns_404(): void
    {
        ['tenant' => $tenantA, 'owner' => $ownerA] = $this->setupTenant();
        ['tenant' => $tenantB, 'branch' => $branchB] = $this->setupTenant();

        app()->instance('currentTenant', $tenantB);
        $expenseB = Expense::factory()->forBranch($branchB)->create();

        // TenantA requests TenantB's expense — BelongsToTenant scope returns null → 404
        app()->instance('currentTenant', $tenantA);

        $this->tenantGetJson($tenantA, $ownerA, "/api/v1/expenses/{$expenseB->id}")
            ->assertStatus(404);
    }

    // ── store (manual creation) ───────────────────────────────────────────────

    public function test_store_manual_creates_verified_expense_with_201(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner] = $this->setupTenant();
        $category = ExpenseCategory::factory()->forTenant($tenant)->create();

        $payload = [
            'description' => 'Compra de papel y lapices',
            'amount_cents' => 7500,
            'expense_date' => '2026-06-10',
            'expense_category_id' => $category->id,
            'branch_id' => $branch->id,
            'vendor' => 'Papeleria Central',
            'payment_method' => 'cash',
            'notes' => 'Para el taller del martes',
        ];

        $response = $this->tenantPostJson($tenant, $owner, '/api/v1/expenses', $payload)
            ->assertStatus(201)
            ->assertJsonPath('data.amount_cents', 7500)
            ->assertJsonPath('data.is_verified', true)
            ->assertJsonPath('data.ocr_status', 'none')
            ->assertJsonPath('data.vendor', 'Papeleria Central');

        $this->assertDatabaseHas('expenses', [
            'tenant_id' => $tenant->id,
            'amount_cents' => 7500,
            'is_verified' => true,
            'ocr_status' => 'none',
            'created_by' => $owner->id,
        ]);

        // creator relation should be loaded in the response
        $this->assertNotNull($response->json('data.creator'));
        $this->assertSame($owner->id, $response->json('data.creator.id'));
    }

    public function test_store_manual_requires_description_and_amount_cents_and_expense_date(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $this->tenantPostJson($tenant, $owner, '/api/v1/expenses', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['description', 'amount_cents', 'expense_date']);
    }

    public function test_store_manual_amount_cents_must_be_non_negative_integer(): void
    {
        ['tenant' => $tenant, 'owner' => $owner] = $this->setupTenant();

        $this->tenantPostJson($tenant, $owner, '/api/v1/expenses', [
            'description' => 'Test',
            'amount_cents' => -500,
            'expense_date' => '2026-06-10',
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['amount_cents']);
    }

    // ── update (edit + verify path) ───────────────────────────────────────────

    public function test_update_edits_expense_fields(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner] = $this->setupTenant();
        $expense = Expense::factory()->forBranch($branch)->create(['amount_cents' => 5000]);

        $this->tenantPatchJson($tenant, $owner, "/api/v1/expenses/{$expense->id}", [
            'amount_cents' => 8800,
            'vendor' => 'Nuevo Proveedor',
        ])->assertOk()
            ->assertJsonPath('data.amount_cents', 8800)
            ->assertJsonPath('data.vendor', 'Nuevo Proveedor');

        $this->assertDatabaseHas('expenses', [
            'id' => $expense->id,
            'amount_cents' => 8800,
            'vendor' => 'Nuevo Proveedor',
        ]);
    }

    public function test_update_with_is_verified_true_confirms_a_draft(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner] = $this->setupTenant();
        $draft = Expense::factory()->forBranch($branch)->draft()->create([
            'amount_cents' => 0,
            'vendor' => null,
        ]);

        $this->assertFalse($draft->fresh()->is_verified);

        $this->tenantPatchJson($tenant, $owner, "/api/v1/expenses/{$draft->id}", [
            'amount_cents' => 15000,
            'vendor' => 'Proveedor OCR Verificado',
            'is_verified' => true,
        ])->assertOk()
            ->assertJsonPath('data.is_verified', true)
            ->assertJsonPath('data.amount_cents', 15000);

        $this->assertTrue($draft->fresh()->is_verified);
    }

    // ── destroy ───────────────────────────────────────────────────────────────

    public function test_destroy_soft_deletes_the_expense(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner] = $this->setupTenant();
        $expense = Expense::factory()->forBranch($branch)->create();

        $this->tenantDeleteJson($tenant, $owner, "/api/v1/expenses/{$expense->id}")
            ->assertStatus(204);

        $this->assertSoftDeleted('expenses', ['id' => $expense->id]);
    }

    public function test_destroy_removes_receipt_file_from_storage(): void
    {
        ['tenant' => $tenant, 'branch' => $branch, 'owner' => $owner] = $this->setupTenant();
        $disk = config('expenses.receipt_disk');
        $storagePath = "tenants/{$tenant->id}/receipts/test-receipt.jpg";

        Storage::disk($disk)->put($storagePath, 'fake-content');

        $expense = Expense::factory()->forBranch($branch)->create([
            'receipt_path' => $storagePath,
        ]);

        $this->tenantDeleteJson($tenant, $owner, "/api/v1/expenses/{$expense->id}")
            ->assertStatus(204);

        Storage::disk($disk)->assertMissing($storagePath);
    }

    public function test_destroy_cross_tenant_expense_returns_404(): void
    {
        ['tenant' => $tenantA, 'owner' => $ownerA] = $this->setupTenant();
        ['tenant' => $tenantB, 'branch' => $branchB] = $this->setupTenant();

        app()->instance('currentTenant', $tenantB);
        $expenseB = Expense::factory()->forBranch($branchB)->create();

        app()->instance('currentTenant', $tenantA);

        $this->tenantDeleteJson($tenantA, $ownerA, "/api/v1/expenses/{$expenseB->id}")
            ->assertStatus(404);
    }
}
