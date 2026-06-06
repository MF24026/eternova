<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Http\Requests\ReorderCategoriesRequest;
use App\Modules\Catalog\Http\Requests\StoreCategoryRequest;
use App\Modules\Catalog\Http\Requests\UpdateCategoryRequest;
use App\Modules\Catalog\Http\Resources\CategoryCollection;
use App\Modules\Catalog\Http\Resources\CategoryResource;
use App\Modules\Catalog\Models\Category;
use App\Modules\Catalog\Repositories\CategoryRepositoryInterface;
use App\Modules\Catalog\Services\CategoryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class CategoryController extends Controller
{
    public function __construct(
        private readonly CategoryRepositoryInterface $categories,
        private readonly CategoryService $categoryService,
    ) {}

    /**
     * Paginated list of categories.
     */
    public function index(Request $request): CategoryCollection
    {
        $this->authorize('viewAny', Category::class);

        $filters = [
            'search' => $request->query('search'),
            'is_active' => $request->has('is_active') ? filter_var($request->query('is_active'), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) : null,
            'parent_id' => $request->query('parent_id'),
            'include_children' => filter_var($request->query('include_children', 'false'), FILTER_VALIDATE_BOOLEAN),
            'per_page' => $request->query('per_page', '20'),
        ];

        return new CategoryCollection(
            $this->categories->paginate($filters)
        );
    }

    /**
     * Single category with children and product count.
     */
    public function show(Category $category): CategoryResource
    {
        $this->authorize('view', $category);

        // Re-fetch with relations so the resource has full data.
        $loaded = $this->categories->findWithRelations($category->id);

        return new CategoryResource($loaded ?? $category);
    }

    /**
     * Create a new category.
     */
    public function store(StoreCategoryRequest $request): JsonResponse
    {
        $category = $this->categoryService->create($request->validated());

        $loaded = $this->categories->findWithRelations($category->id);

        return (new CategoryResource($loaded ?? $category))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Update an existing category.
     */
    public function update(UpdateCategoryRequest $request, Category $category): CategoryResource
    {
        $updated = $this->categoryService->update($category, $request->validated());

        $loaded = $this->categories->findWithRelations($updated->id);

        return new CategoryResource($loaded ?? $updated);
    }

    /**
     * Soft-delete a category. Children are orphaned (parent_id → null).
     */
    public function destroy(Category $category): JsonResponse
    {
        $this->authorize('delete', $category);

        $this->categoryService->delete($category);

        return response()->json(null, 204);
    }

    /**
     * Restore a soft-deleted category.
     */
    public function restore(Category $category): CategoryResource
    {
        $this->authorize('restore', $category);

        $this->categoryService->restore($category);

        $loaded = $this->categories->findWithRelations($category->id);

        return new CategoryResource($loaded ?? $category);
    }

    /**
     * Bulk reorder categories by updating sort_order and parent_id.
     */
    public function reorder(ReorderCategoriesRequest $request): JsonResponse
    {
        /** @var array<int, array{id: int, sort_order: int, parent_id: int|null}> $items */
        $items = $request->validated()['items'];

        $this->categoryService->reorder($items);

        return response()->json(null, 204);
    }
}
