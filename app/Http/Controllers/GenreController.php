<?php

namespace App\Http\Controllers;

use App\Http\Requests\MergeGenresRequest;
use App\Http\Requests\StoreGenreRequest;
use App\Http\Requests\UpdateGenreRequest;
use App\Models\Author;
use App\Models\Book;
use App\Models\Genre;
use App\Services\BookService;
use App\Services\Exceptions\GenreInUseException;
use App\Services\Exceptions\GenreNameConflictException;
use App\Services\GenreService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

class GenreController extends Controller
{
    protected $bookService;

    protected GenreService $genreService;

    public function __construct(BookService $bookService, GenreService $genreService)
    {
        $this->bookService = $bookService;
        $this->genreService = $genreService;

        $this->authorizeResource(Genre::class, 'genre');
    }

    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index()
    {
        $genres = Genre::withCount('books')
            ->orderByRaw('CASE WHEN name REGEXP "^[0-9]" THEN 2 ELSE 1 END, name')
            ->get();

        return response()->json($genres);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(StoreGenreRequest $request): JsonResponse
    {
        try {
            $genre = $this->genreService->create($request->name());
        } catch (GenreNameConflictException $e) {
            return $this->conflictResponse($e);
        }

        return response()->json($genre->loadCount('books'), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Genre $genre): JsonResponse
    {
        $genre_id = $genre->genre_id;

        $query = Book::with('authors', 'versions', 'versions.format', 'genres', 'readInstances')
            ->selectRaw(
                'books.book_id, books.title, books.slug, MIN('
                .Author::sortNameExpression()
                .') as primary_author_last_name'
            )
            ->leftJoin('book_author', 'books.book_id', '=', 'book_author.book_id')
            ->leftJoin('authors', 'authors.author_id', '=', 'book_author.author_id')
            ->leftJoin('read_instances', 'books.book_id', '=', 'read_instances.book_id')
            ->whereHas('genres', function ($q) use ($genre_id) {
                $q->where('genres.genre_id', $genre_id);
            })
            ->groupBy('books.book_id', 'books.title', 'books.slug');

        // Sort by the name each book's authors file under — a surname where
        // there is one, the single name otherwise. See
        // `Author::sortNameExpression()`.
        $query->orderBy('primary_author_last_name', 'asc');

        // Determine the pagination size, default to 20 if not specified
        $pageSize = $request->input('limit', 20);

        // Paginate the results
        $books = $query->paginate($pageSize);

        $formattedBooks = $this->bookService->getBooksList(collect($books->items()));

        // Return paginated results
        return response()->json([
            'genre' => [
                'genre_id' => $genre->genre_id,
                'name' => $genre->name,
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

    /**
     * Rename the specified resource.
     */
    public function update(UpdateGenreRequest $request, Genre $genre): JsonResponse
    {
        try {
            $genre = $this->genreService->rename($genre, $request->name());
        } catch (GenreNameConflictException $e) {
            return $this->conflictResponse($e);
        }

        return response()->json($genre->loadCount('books'));
    }

    /**
     * Remove the specified resource from storage.
     *
     * `?force=true` is the acknowledgement that this detaches the genre from
     * every book still using it. Without it, a genre with books is a 409.
     */
    public function destroy(Request $request, Genre $genre): JsonResponse
    {
        try {
            $this->genreService->delete($genre, $request->boolean('force'));
        } catch (GenreInUseException $e) {
            return response()->json([
                'reason_code' => $e->reasonCode,
                'reason' => $e->getMessage(),
                'books_count' => $e->booksCount,
            ], 409);
        }

        return response()->json(['deleted' => true]);
    }

    /**
     * Fold `source_ids` into `{genre}`, the winner.
     *
     * `merge` isn't one of the seven verbs `authorizeResource()` maps, so the
     * gate is explicit.
     */
    public function merge(MergeGenresRequest $request, Genre $genre): JsonResponse
    {
        Gate::authorize('merge', $genre);

        return response()->json($this->genreService->merge($genre, $request->sourceIds()));
    }

    /**
     * The conflicting genre travels in the body so the SPA can offer "merge
     * into it instead" without going back to the server for its id.
     */
    private function conflictResponse(GenreNameConflictException $e): JsonResponse
    {
        return response()->json([
            'reason_code' => $e->reasonCode,
            'reason' => $e->getMessage(),
            'conflict' => $e->conflict->loadCount('books'),
        ], 409);
    }
}
