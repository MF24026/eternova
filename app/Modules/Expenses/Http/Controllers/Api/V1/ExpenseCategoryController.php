<?php

declare(strict_types=1);

namespace App\Modules\Expenses\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Expenses\Http\Requests\StoreExpenseCategoryRequest;
use App\Modules\Expenses\Http\Requests\UpdateExpenseCategoryRequest;
use App\Modules\Expenses\Http\Resources\ExpenseCategoryResource;
use App\Modules\Expenses\Models\Expense;
use App\Modules\Expenses\Models\ExpenseCategory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * Expense category management REST endpoints.
 *
 * Thin controller — categories have no heavy business logic so a dedicated
 * service is unnecessary. All DB access goes through the model directly here
 * (no repository needed: categories don't have complex pagination or filters).
 *
 * Authorization:
 *   - index: viewAny(Expense::class) — staff need the list to populate the expense form.
 *   - store/update/destroy: manageCategories(Expense::class) — restricted to owner|admin.
 *
 * Delete decision:
 *   If the category has any associated expenses (including soft-deleted ones) we
 *   return 422 "category in use" rather than silently nulling the FK. This is the
 *   safe choice: nulling expense_category_id on historical expense records would
 *   silently break the expense report grouping. The caller should retire the
 *   category (set is_active=false) instead of deleting it.
 *
 * Route-model binding:
 *   {category} resolves via the BelongsToTenant global scope on ExpenseCategory,
 *   so a cross-tenant category id naturally 404s before reaching this controller.
 */
final class ExpenseCategoryController extends Controller
{
    /**
     * List all expense categories for the current tenant, ordered by name.
     *
     * Returns active AND inactive categories so the frontend can show the full
     * list in the settings view. The expense form should filter to is_active=true
     * on the client side.
     */
    public function index(): JsonResponse
    {
        $this->authorize('viewAny', Expense::class);

        $categories = ExpenseCategory::withCount('expenses')
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => ExpenseCategoryResource::collection($categories),
        ]);
    }

    /**
     * Create a new expense category for the current tenant.
     *
     * tenant_id is injected from the active tenant context so the caller
     * cannot supply it in the request body (they could try, but validated()
     * only returns declared fields).
     */
    public function store(StoreExpenseCategoryRequest $request): JsonResponse
    {
        $this->authorize('manageCategories', Expense::class);

        $data = $request->validated();
        $tenantId = current_tenant()?->id;

        $category = ExpenseCategory::create([
            'tenant_id' => $tenantId,
            'name'      => (string) $data['name'],
            'type'      => (string) $data['type'],
            'is_active' => (bool) ($data['is_active'] ?? true),
        ]);

        Log::info('Expense category created', [
            'category_id' => $category->id,
            'tenant_id'   => $tenantId,
            'name'        => $category->name,
        ]);

        return (new ExpenseCategoryResource($category))
            ->response()
            ->setStatusCode(Response::HTTP_CREATED);
    }

    /**
     * Update an expense category's name, type, or active status.
     *
     * Renaming uses the UpdateExpenseCategoryRequest unique rule which ignores
     * the current row, so a no-op rename does not trigger a uniqueness violation.
     */
    public function update(UpdateExpenseCategoryRequest $request, ExpenseCategory $category): JsonResponse
    {
        $this->authorize('manageCategories', Expense::class);

        $data = $request->validated();

        $category->update($data);

        Log::info('Expense category updated', [
            'category_id' => $category->id,
            'tenant_id'   => $category->tenant_id,
            'fields'      => array_keys($data),
        ]);

        return response()->json(['data' => new ExpenseCategoryResource($category)]);
    }

    /**
     * Delete an expense category.
     *
     * Blocked with 422 when the category has any associated expenses (including
     * soft-deleted ones). Use PATCH to set is_active=false to retire a category
     * without breaking expense history.
     *
     * We count withTrashed() because soft-deleted expenses still reference the
     * category for reporting; nulling the FK there would silently break E5 reports.
     */
    public function destroy(ExpenseCategory $category): Response|JsonResponse
    {
        $this->authorize('manageCategories', Expense::class);

        $expenseCount = $category->expenses()->withTrashed()->count();

        if ($expenseCount > 0) {
            Log::notice('Expense category delete blocked — category in use', [
                'category_id'   => $category->id,
                'tenant_id'     => $category->tenant_id,
                'expense_count' => $expenseCount,
            ]);

            return response()->json([
                'message'    => "Cannot delete category \"{$category->name}\" — it has {$expenseCount} associated expense(s). "
                    . 'Set is_active=false to retire it instead.',
                'error_code' => 'expenses.category_in_use',
            ], 422);
        }

        Log::info('Expense category deleted', [
            'category_id' => $category->id,
            'tenant_id'   => $category->tenant_id,
            'name'        => $category->name,
        ]);

        $category->delete();

        return response()->noContent();
    }
}
