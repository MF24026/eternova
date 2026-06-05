<?php

declare(strict_types=1);

namespace Tests\Feature\Api;

use App\Exceptions\PlanGateException;
use App\Http\Middleware\InjectRequestId;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Verifies the response envelope contract, X-Request-Id propagation, and the
 * standardised error format for all API /api/v1/* routes.
 *
 * Inline test routes are registered once in setUpBeforeClass() so the route
 * list is not rebuilt on every test method. We do NOT use RefreshDatabase
 * because none of these tests touch actual database records — they only assert
 * HTTP structure. The 404 test triggers a ModelNotFoundException directly from
 * the route closure without requiring a real DB query.
 */
final class EnvelopeTest extends TestCase
{
    /**
     * Register disposable in-test routes before the first test in this class.
     * These routes exercise each error scenario and are wrapped in the same
     * middleware group as real API routes, so InjectRequestId and the exception
     * handler apply identically.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Route::middleware(['api', InjectRequestId::class])
            ->prefix('api/v1/test-envelope')
            ->group(function (): void {
                // 422: route with a FormRequest that requires 'name'
                Route::post('/validate', static function (TestRequiresNameRequest $request): JsonResponse {
                    return response()->json(['data' => ['name' => $request->validated('name')], 'meta' => []]);
                });

                // 404 ModelNotFoundException: thrown directly (no real DB needed)
                Route::get('/not-found', static function (): never {
                    throw new ModelNotFoundException;
                });

                // 402 PlanGateException
                Route::get('/plan-gate', static function (): never {
                    throw PlanGateException::for('multi_branch_inventory', 'basico', 'pro');
                });
            });
    }

    // ─── Health endpoint ────────────────────────────────────────────────────

    public function test_health_endpoint_returns_envelope_with_data_and_meta(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => ['status', 'version'],
                'meta' => ['request_id'],
            ])
            ->assertJsonPath('data.status', 'ok')
            ->assertJsonPath('data.version', 'v1');

        $requestId = $response->json('meta.request_id');

        $this->assertNotEmpty($requestId);
        $this->assertTrue(
            Str::isUuid($requestId),
            "Expected meta.request_id to be a valid UUID, got: {$requestId}",
        );
    }

    // ─── X-Request-Id propagation ───────────────────────────────────────────

    public function test_x_request_id_header_is_propagated_when_provided(): void
    {
        $customId = 'my-trace-123';

        $response = $this->getJson('/api/v1/health', ['X-Request-Id' => $customId]);

        $response->assertStatus(200)
            ->assertHeader('X-Request-Id', $customId)
            ->assertJsonPath('meta.request_id', $customId);
    }

    public function test_x_request_id_is_generated_when_absent(): void
    {
        $response = $this->getJson('/api/v1/health');

        $response->assertStatus(200);

        $headerValue = $response->headers->get('X-Request-Id');
        $metaValue = $response->json('meta.request_id');

        $this->assertNotNull($headerValue, 'Response must include X-Request-Id header');
        $this->assertNotNull($metaValue, 'Response meta must include request_id');
        $this->assertTrue(Str::isUuid($headerValue), "X-Request-Id header must be a UUID, got: {$headerValue}");
        $this->assertSame($headerValue, $metaValue, 'Header value and meta.request_id must match');
    }

    // ─── 422 Validation error ───────────────────────────────────────────────

    public function test_validation_error_returns_422_with_envelope(): void
    {
        // POST without 'name' field to trigger validation failure.
        $response = $this->postJson('/api/v1/test-envelope/validate', []);

        $response->assertStatus(422)
            ->assertJsonStructure([
                'message',
                'errors' => ['name'],
                'meta' => ['request_id'],
            ]);

        $this->assertIsArray($response->json('errors.name'));
        $this->assertNotEmpty($response->json('errors.name'));
        $this->assertNotEmpty($response->json('meta.request_id'));
    }

    // ─── 404 ModelNotFoundException ─────────────────────────────────────────

    public function test_model_not_found_returns_404_with_error_code(): void
    {
        // The test route throws ModelNotFoundException directly — no DB needed.
        $response = $this->getJson('/api/v1/test-envelope/not-found');

        $response->assertStatus(404)
            ->assertJsonPath('error_code', 'resource.not_found')
            ->assertJsonStructure(['message', 'error_code', 'meta' => ['request_id']]);
    }

    // ─── 402 PlanGateException ──────────────────────────────────────────────

    public function test_plan_gate_exception_returns_402_with_feature_metadata(): void
    {
        $response = $this->getJson('/api/v1/test-envelope/plan-gate');

        $response->assertStatus(402)
            ->assertJsonPath('error_code', 'plan.feature_locked')
            ->assertJsonPath('feature', 'multi_branch_inventory')
            ->assertJsonPath('current_plan', 'basico')
            ->assertJsonPath('required_plan', 'pro')
            ->assertJsonStructure([
                'message',
                'error_code',
                'feature',
                'current_plan',
                'required_plan',
                'meta' => ['request_id'],
            ]);
    }
}

/**
 * Inline FormRequest used only by EnvelopeTest to trigger a 422.
 * Defined here (same file) to avoid polluting the app namespace.
 */
final class TestRequiresNameRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string'],
        ];
    }
}
