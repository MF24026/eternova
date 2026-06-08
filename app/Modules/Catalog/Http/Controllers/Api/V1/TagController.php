<?php

declare(strict_types=1);

namespace App\Modules\Catalog\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Modules\Catalog\Http\Requests\StoreTagRequest;
use App\Modules\Catalog\Http\Requests\UpdateTagRequest;
use App\Modules\Catalog\Http\Resources\TagCollection;
use App\Modules\Catalog\Http\Resources\TagResource;
use App\Modules\Catalog\Models\Tag;
use App\Modules\Catalog\Repositories\TagRepositoryInterface;
use App\Modules\Catalog\Services\TagService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class TagController extends Controller
{
    public function __construct(
        private readonly TagRepositoryInterface $tags,
        private readonly TagService $tagService,
    ) {}

    /**
     * Paginated list of tags for the current tenant.
     */
    public function index(Request $request): TagCollection
    {
        $this->authorize('viewAny', Tag::class);

        $filters = [
            'search' => $request->query('search'),
            'per_page' => $request->query('per_page', '20'),
        ];

        return new TagCollection($this->tags->paginate($filters));
    }

    /**
     * Create a new tag.
     */
    public function store(StoreTagRequest $request): JsonResponse
    {
        $this->authorize('create', Tag::class);

        $tag = $this->tagService->create($request->validated());

        return (new TagResource($tag))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Update an existing tag.
     */
    public function update(UpdateTagRequest $request, Tag $tag): TagResource
    {
        $this->authorize('update', $tag);

        $updated = $this->tagService->update($tag, $request->validated());

        return new TagResource($updated);
    }

    /**
     * Hard-delete a tag. Tags have no soft-delete per ERD §2.6.
     */
    public function destroy(Tag $tag): JsonResponse
    {
        $this->authorize('delete', $tag);

        $this->tagService->delete($tag);

        return response()->json(null, 204);
    }
}
