<?php

declare(strict_types=1);

namespace Tests\Feature\Expenses;

use App\Models\User;
use App\Modules\Expenses\Jobs\ProcessReceiptOcrJob;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Ocr\FakeOcrDriver;
use App\Modules\Expenses\Ocr\OcrDriverInterface;
use App\Modules\Expenses\Ocr\OcrException;
use App\Modules\Expenses\Ocr\OcrResult;
use App\Modules\Tenancy\Models\Branch;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Support\ActingAsTenantMember;
use Tests\TestCase;

/**
 * Feature tests for S6-E3: receipt upload endpoint + ProcessReceiptOcrJob.
 *
 * phpunit.xml sets OCR_DRIVER=fake, so all tests use FakeOcrDriver —
 * deterministic, no Tesseract binary required.
 *
 * Storage::fake() prevents writes to the real disk.
 * Bus::fake() prevents real job dispatch in upload tests.
 */
final class ReceiptUploadTest extends TestCase
{
    use ActingAsTenantMember;
    use RefreshDatabase;

    private Tenant $tenant;

    private Branch $branch;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenancy.cache.enabled' => false]);
        Cache::flush();

        Storage::fake(config('expenses.receipt_disk'));

        $this->tenant = Tenant::factory()->create(['slug' => 'expense-test-tenant']);
        $this->branch = Branch::factory()->forTenant($this->tenant)->create(['is_main' => true]);
        $this->owner = User::factory()->forTenant($this->tenant, role: 'owner')->create();
    }

    // ── Upload creates DRAFT + dispatches job ─────────────────────────────────

    public function test_upload_stores_file_creates_draft_expense_and_dispatches_ocr_job(): void
    {
        Bus::fake();

        $file = UploadedFile::fake()->image('receipt.jpg', 800, 600);

        $response = $this->uploadReceipt($file);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'data' => [
                    'id',
                    'ocr_status',
                    'is_verified',
                    'amount_cents',
                    'expense_date',
                    'description',
                    'vendor',
                    'receipt_url',
                    'ocr_data',
                    'created_at',
                ],
            ]);

        $expenseId = $response->json('data.id');

        // Draft state
        $this->assertDatabaseHas('expenses', [
            'id' => $expenseId,
            'tenant_id' => $this->tenant->id,
            'ocr_status' => 'pending',
            'is_verified' => false,
            'amount_cents' => 0,
        ]);

        // File stored on disk under the tenant-scoped path
        $expense = Expense::withoutGlobalScopes()->find($expenseId);
        $this->assertNotNull($expense->receipt_path);
        $this->assertStringContainsString("tenants/{$this->tenant->id}/receipts/", $expense->receipt_path);
        Storage::disk(config('expenses.receipt_disk'))->assertExists($expense->receipt_path);

        // OCR job dispatched
        Bus::assertDispatched(ProcessReceiptOcrJob::class);
    }

    public function test_upload_links_expense_to_the_provided_branch(): void
    {
        Bus::fake();

        $file = UploadedFile::fake()->image('receipt.jpg', 400, 300);

        $response = $this->uploadReceiptWithData($file, ['branch_id' => $this->branch->id]);

        $response->assertStatus(201);

        $expenseId = $response->json('data.id');
        $this->assertDatabaseHas('expenses', [
            'id' => $expenseId,
            'branch_id' => $this->branch->id,
        ]);
    }

    public function test_upload_records_authenticated_user_as_creator(): void
    {
        Bus::fake();

        $file = UploadedFile::fake()->image('receipt.jpg', 400, 300);

        $response = $this->uploadReceipt($file);

        $response->assertStatus(201);

        $this->assertDatabaseHas('expenses', [
            'id' => $response->json('data.id'),
            'created_by' => $this->owner->id,
        ]);
    }

    // ── Validation ────────────────────────────────────────────────────────────

    public function test_upload_rejects_disallowed_mime_type(): void
    {
        $txtFile = UploadedFile::fake()->create('notes.txt', 10, 'text/plain');

        $response = $this->uploadReceipt($txtFile);

        $response->assertStatus(422)
            ->assertJsonValidationErrorFor('receipt');
    }

    public function test_upload_rejects_file_over_10mb(): void
    {
        // 10241 KB > 10 MB limit (10240 KB)
        $bigFile = UploadedFile::fake()->create('large.jpg', 10241, 'image/jpeg');

        $response = $this->uploadReceipt($bigFile);

        $response->assertStatus(422)
            ->assertJsonValidationErrorFor('receipt');
    }

    public function test_upload_rejects_missing_receipt_field(): void
    {
        $response = $this->actingAs($this->owner)
            ->postJson($this->tenantUrl($this->tenant, '/api/v1/expenses/receipt'), []);

        $response->assertStatus(422)
            ->assertJsonValidationErrorFor('receipt');
    }

    // ── Job: OCR sets status done + populates ocr_data ────────────────────────

    public function test_job_sets_ocr_status_done_and_populates_ocr_data(): void
    {
        // Do NOT use Bus::fake() — we want the job to actually run.
        app()->instance('currentTenant', $this->tenant);

        $expense = Expense::factory()
            ->forBranch($this->branch)
            ->create([
                'ocr_status' => 'pending',
                'is_verified' => false,
                'receipt_path' => "tenants/{$this->tenant->id}/receipts/test.jpg",
            ]);

        // Put a fake file on the fake disk so Storage::disk()->path() resolves.
        Storage::disk(config('expenses.receipt_disk'))
            ->put($expense->receipt_path, 'fake-image-content');

        // Dispatch synchronously using the container-resolved FakeOcrDriver.
        $job = new ProcessReceiptOcrJob((int) $expense->id);
        $job->handle(app(OcrDriverInterface::class));

        $expense->refresh();

        $this->assertSame('done', $expense->ocr_status);
        $this->assertNotNull($expense->ocr_data);
        $this->assertSame(FakeOcrDriver::VENDOR, $expense->ocr_data['vendor']);
        $this->assertSame(FakeOcrDriver::AMOUNT_CENTS, $expense->ocr_data['amount_cents']);
        $this->assertSame(FakeOcrDriver::DATE, $expense->ocr_data['date']);
        $this->assertArrayHasKey('raw_text', $expense->ocr_data);

        // Pre-filled columns from OCR suggestions
        $this->assertSame(FakeOcrDriver::AMOUNT_CENTS, $expense->amount_cents);
        $this->assertSame(FakeOcrDriver::VENDOR, $expense->vendor);

        // is_verified must remain false — the human gate was not triggered.
        $this->assertFalse($expense->is_verified);
    }

    public function test_job_sets_ocr_status_failed_when_driver_throws_ocr_exception(): void
    {
        app()->instance('currentTenant', $this->tenant);

        $expense = Expense::factory()
            ->forBranch($this->branch)
            ->create([
                'ocr_status' => 'pending',
                'is_verified' => false,
                'receipt_path' => "tenants/{$this->tenant->id}/receipts/test.jpg",
            ]);

        Storage::disk(config('expenses.receipt_disk'))
            ->put($expense->receipt_path, 'fake-image-content');

        // Bind a driver stub that always throws OcrException.
        $this->app->bind(OcrDriverInterface::class, static function (): OcrDriverInterface {
            return new class implements OcrDriverInterface
            {
                public function extract(string $absolutePath): OcrResult
                {
                    throw new OcrException('Simulated OCR failure for test.');
                }
            };
        });

        $job = new ProcessReceiptOcrJob((int) $expense->id);
        $job->handle(app(OcrDriverInterface::class));

        $expense->refresh();

        $this->assertSame('failed', $expense->ocr_status);
        $this->assertNull($expense->ocr_data);
        // The job must not crash the application.
    }

    // ── OCR-status polling endpoint ───────────────────────────────────────────

    public function test_ocr_status_endpoint_returns_status_and_ocr_data(): void
    {
        app()->instance('currentTenant', $this->tenant);

        $expense = Expense::factory()
            ->forBranch($this->branch)
            ->draft()
            ->create();

        $response = $this->tenantGetJson(
            $this->tenant,
            $this->owner,
            "/api/v1/expenses/{$expense->id}/ocr-status",
        );

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['ocr_status', 'ocr_data'],
            ])
            ->assertJsonPath('data.ocr_status', $expense->ocr_status);
    }

    public function test_ocr_status_endpoint_returns_pending_for_freshly_uploaded_draft(): void
    {
        app()->instance('currentTenant', $this->tenant);

        $expense = Expense::factory()
            ->forBranch($this->branch)
            ->create(['ocr_status' => 'pending', 'is_verified' => false]);

        $response = $this->tenantGetJson(
            $this->tenant,
            $this->owner,
            "/api/v1/expenses/{$expense->id}/ocr-status",
        );

        $response->assertStatus(200)
            ->assertJsonPath('data.ocr_status', 'pending')
            ->assertJsonPath('data.ocr_data', null);
    }

    // ── Multi-tenant isolation ────────────────────────────────────────────────

    public function test_upload_creates_expense_scoped_to_authenticated_user_tenant(): void
    {
        Bus::fake();

        $tenantB = Tenant::factory()->create(['slug' => 'expense-test-tenant-b']);
        $ownerB = User::factory()->forTenant($tenantB, role: 'owner')->create();

        // Upload as tenant B's owner — must create expense for tenant B.
        $file = UploadedFile::fake()->image('receipt.jpg', 400, 300);

        app()->instance('currentTenant', $tenantB);

        $response = $this->actingAs($ownerB)
            ->call(
                'POST',
                $this->tenantUrl($tenantB, '/api/v1/expenses/receipt'),
                [],
                [],
                ['receipt' => $file],
                ['HTTP_ACCEPT' => 'application/json'],
            );

        $response->assertStatus(201);

        $expenseId = $response->json('data.id');
        $this->assertDatabaseHas('expenses', [
            'id' => $expenseId,
            'tenant_id' => $tenantB->id,
        ]);

        // Tenant A must not see tenant B's expense.
        app()->instance('currentTenant', $this->tenant);
        $this->assertSame(0, Expense::where('id', $expenseId)->count());
    }

    public function test_user_of_tenant_a_cannot_poll_ocr_status_of_tenant_b_expense(): void
    {
        $tenantB = Tenant::factory()->create(['slug' => 'expense-test-tenant-b-iso']);
        $branchB = Branch::factory()->forTenant($tenantB)->create();

        app()->instance('currentTenant', $tenantB);
        $expenseB = Expense::factory()->forBranch($branchB)->draft()->create();

        // Tenant A's owner tries to access tenant B's expense.
        $response = $this->tenantGetJson(
            $this->tenant,
            $this->owner,
            "/api/v1/expenses/{$expenseB->id}/ocr-status",
        );

        // Both 404 (BelongsToTenant scope filters the row) and 403 (policy denies)
        // are valid security responses for cross-tenant access attempts.
        $this->assertContains($response->status(), [403, 404]);
    }

    // ── Auth ─────────────────────────────────────────────────────────────────

    public function test_unauthenticated_upload_returns_401(): void
    {
        $file = UploadedFile::fake()->image('receipt.jpg', 400, 300);

        $response = $this->call(
            'POST',
            $this->tenantUrl($this->tenant, '/api/v1/expenses/receipt'),
            [],
            [],
            ['receipt' => $file],
            ['HTTP_ACCEPT' => 'application/json'],
        );

        $response->assertStatus(401);
    }

    public function test_unauthenticated_ocr_status_request_returns_401(): void
    {
        app()->instance('currentTenant', $this->tenant);
        $expense = Expense::factory()->forBranch($this->branch)->draft()->create();

        $this->getJson($this->tenantUrl($this->tenant, "/api/v1/expenses/{$expense->id}/ocr-status"))
            ->assertStatus(401);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Send a multipart POST to the receipt upload endpoint as the default owner.
     */
    private function uploadReceipt(UploadedFile $file): TestResponse
    {
        return $this->uploadReceiptWithData($file, []);
    }

    /**
     * Send a multipart POST with extra fields (e.g. branch_id) as the default owner.
     *
     * @param  array<string, mixed>  $extraData
     */
    private function uploadReceiptWithData(UploadedFile $file, array $extraData): TestResponse
    {
        return $this->actingAs($this->owner)
            ->call(
                'POST',
                $this->tenantUrl($this->tenant, '/api/v1/expenses/receipt'),
                $extraData,
                [],
                ['receipt' => $file],
                ['HTTP_ACCEPT' => 'application/json'],
            );
    }
}
