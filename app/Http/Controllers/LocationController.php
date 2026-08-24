<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLocationRequest;
use App\Http\Requests\UpdateLocationRequest;
use App\Models\Location;
use App\Models\Version;
use App\Services\BookService;
use App\Services\Exceptions\LocationCodeConflictException;
use App\Services\Exceptions\LocationCycleException;
use App\Services\Exceptions\LocationHasChildrenException;
use App\Services\Exceptions\LocationInUseException;
use App\Services\LocationService;
use App\Support\BookListing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LocationController extends Controller
{
    public function __construct(
        protected BookService $bookService,
        protected LocationService $locationService,
    ) {
        $this->authorizeResource(Location::class, 'location');
    }

    /**
     * The whole tree, flat — a couple of dozen rows, so the SPA fetches it
     * once and builds the hierarchy from `parent_id` client-side.
     */
    public function index(): JsonResponse
    {
        $locations = Location::withCount('versions')
            ->orderBy('parent_id')
            ->orderBy('ordinal')
            ->orderBy('code')
            ->get();

        return response()->json($locations);
    }

    public function store(StoreLocationRequest $request): JsonResponse
    {
        try {
            $location = $this->locationService->create($request->locationAttributes());
        } catch (LocationCodeConflictException $e) {
            return $this->conflictResponse($e);
        }

        return response()->json($location->loadCount('versions'), 201);
    }

    /**
     * One location with its context: the breadcrumb up, the children down.
     * The books themselves are the separate paginated {@see books()} call.
     */
    public function show(Location $location): JsonResponse
    {
        // One grouped count over the whole subtree serves both the top-level
        // total and each child's rollup. `versions_count` alone would render
        // a bookcase as empty — its copies sit on its shelves, not on it.
        $countsByLocation = Version::whereIn('location_id', $location->subtreeIds())
            ->selectRaw('location_id, COUNT(*) as total')
            ->groupBy('location_id')
            ->pluck('total', 'location_id');

        $children = $location->children()->withCount('versions')->get()
            ->each(fn (Location $child) => $child->setAttribute(
                'subtree_versions_count',
                collect($child->subtreeIds())->sum(fn ($id) => $countsByLocation[$id] ?? 0),
            ));

        return response()->json([
            'location' => $location->loadCount('versions'),
            'ancestors' => $location->ancestors()->values(),
            'children' => $children,
            'subtree_versions_count' => $countsByLocation->sum(),
        ]);
    }

    /**
     * Paginated book listing for the location's whole subtree — a bookcase is
     * the union of its shelves, a room of its bookcases. Built on the same
     * `BookListing` the library and genre pages use; the one addition is the
     * `shelf` sort (left-to-right physical order), which is the default when
     * the location is itself a shelf.
     */
    public function books(Request $request, Location $location): JsonResponse
    {
        $subtreeIds = $location->subtreeIds();

        $query = BookListing::query()
            ->whereHas('versions', function ($q) use ($subtreeIds) {
                $q->whereIn('versions.location_id', $subtreeIds);
            });

        $sort = strtolower((string) $request->input('sort', $location->kind === 'shelf' ? 'shelf' : ''));

        if ($sort === 'shelf') {
            // Not in BookListing::SORTABLE because it only means something
            // inside one location's subtree — the subquery needs the ids.
            $direction = strtolower((string) $request->input('direction')) === 'desc' ? 'desc' : 'asc';

            $query->addSelect([
                'sort_shelf' => Version::select('versions.shelf_ordinal')
                    ->whereColumn('versions.book_id', 'books.book_id')
                    ->whereIn('versions.location_id', $subtreeIds)
                    ->orderBy('versions.shelf_ordinal')
                    ->limit(1),
            ])
                ->orderByRaw('(sort_shelf is null) asc')
                ->orderBy('sort_shelf', $direction)
                ->orderBy('books.book_id', 'asc');
        } else {
            BookListing::sort($query, $request->input('sort'), $request->input('direction'));
        }

        $pageSize = $request->input('limit', 20);
        $books = $query->paginate($pageSize);

        $formattedBooks = $this->bookService->getBooksList(collect($books->items()));

        return response()->json([
            'location' => [
                'location_id' => $location->location_id,
                'code' => $location->code,
                'name' => $location->name,
                'kind' => $location->kind,
                'slug' => $location->slug,
            ],
            'books' => $formattedBooks,
            'pagination' => [
                'total' => $books->total(),
                'perPage' => $books->perPage(),
                'currentPage' => $books->currentPage(),
                'lastPage' => $books->lastPage(),
                'from' => $books->firstItem(),
                'to' => $books->lastItem(),
            ],
        ]);
    }

    public function update(UpdateLocationRequest $request, Location $location): JsonResponse
    {
        try {
            $location = $this->locationService->update($location, $request->locationAttributes());
        } catch (LocationCodeConflictException $e) {
            return $this->conflictResponse($e);
        } catch (LocationCycleException $e) {
            return response()->json([
                'reason_code' => $e->reasonCode,
                'reason' => $e->getMessage(),
            ], 422);
        }

        return response()->json($location->loadCount('versions'));
    }

    /**
     * `?force=true` acknowledges that this unshelves every copy on the
     * location. A location with child locations refuses regardless — the
     * structure is deleted leaf-first, explicitly, never through a parent.
     */
    public function destroy(Request $request, Location $location): JsonResponse
    {
        try {
            $this->locationService->delete($location, $request->boolean('force'));
        } catch (LocationHasChildrenException $e) {
            return response()->json([
                'reason_code' => $e->reasonCode,
                'reason' => $e->getMessage(),
                'children_count' => $e->childrenCount,
            ], 409);
        } catch (LocationInUseException $e) {
            return response()->json([
                'reason_code' => $e->reasonCode,
                'reason' => $e->getMessage(),
                'versions_count' => $e->versionsCount,
            ], 409);
        }

        return response()->json(['deleted' => true]);
    }

    private function conflictResponse(LocationCodeConflictException $e): JsonResponse
    {
        return response()->json([
            'reason_code' => $e->reasonCode,
            'reason' => $e->getMessage(),
            'conflict' => $e->conflict->loadCount('versions'),
        ], 409);
    }
}
