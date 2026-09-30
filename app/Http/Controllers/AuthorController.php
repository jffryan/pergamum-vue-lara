<?php

namespace App\Http\Controllers;

use App\Http\Requests\MergeAuthorsRequest;
use App\Http\Requests\UpdateAuthorRequest;
use App\Models\Author;
use App\Services\AuthorService;
use App\Services\Exceptions\AuthorNameConflictException;
use App\Support\Slugger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class AuthorController extends Controller
{
    protected $authorService;

    public function __construct(AuthorService $authorService)
    {
        $this->authorService = $authorService;
    }

    /**
     * Every author with how many books credit them, in filing order — the
     * admin screen's list. Unpaginated, like `GET /genres`; the catalog is a
     * thousand authors, which is one small payload.
     */
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Author::class);

        $authors = Author::select(['author_id', 'first_name', 'last_name', 'slug'])
            ->withCount('books')
            ->orderByRaw(Author::sortNameExpression())
            ->orderBy('first_name')
            ->get();

        return response()->json($authors);
    }

    public function getAuthorBySlug($slug)
    {
        $response = $this->authorService->getAuthorWithRelations($slug, 'slug');

        return response()->json($response);
    }

    public function getOrSetToBeCreatedAuthorsByName(Request $request)
    {
        $authors_data = $request['authorsData'];
        $authors = [];
        foreach ($authors_data as $author) {
            $name = $author['name'];
            $slug = Slugger::for($name);

            // Look for an existing author by the slug
            $existingAuthor = Author::where('slug', $slug)->first();

            if ($existingAuthor) {
                $authors[] = $existingAuthor;
            } else {
                $data = [
                    'author_id' => null,
                    'first_name' => $author['first_name'], // Include first name
                    'last_name' => $author['last_name'],  // Include last name
                    'slug' => $slug,
                ];

                $authors[] = $data;
            }
        }

        return response()->json(['authors' => $authors]);
    }

    /**
     * Rename an author. The response carries the (possibly new) slug, which
     * is the author page's URL.
     */
    public function update(UpdateAuthorRequest $request, Author $author): JsonResponse
    {
        Gate::authorize('update', $author);

        try {
            $author = $this->authorService->rename($author, $request->names());
        } catch (AuthorNameConflictException $e) {
            return self::conflictResponse($e);
        }

        return response()->json($author->loadCount('books'));
    }

    /**
     * Fold `source_ids` into `{author}`, the winner.
     */
    public function merge(MergeAuthorsRequest $request, Author $author): JsonResponse
    {
        Gate::authorize('merge', $author);

        return response()->json($this->authorService->merge($author, $request->sourceIds()));
    }

    /**
     * The other author travels in the body so the SPA can offer "merge into
     * them instead" without looking them up. Public because the book edit
     * form renames through the same service and answers the same way.
     */
    public static function conflictResponse(AuthorNameConflictException $e): JsonResponse
    {
        return response()->json([
            'reason_code' => $e->reasonCode,
            'reason' => $e->getMessage(),
            'conflict' => $e->conflict->loadCount('books'),
        ], 409);
    }
}
