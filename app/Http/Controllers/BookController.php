<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBookRequest;
use App\Http\Requests\StoreReadInstanceRequest;
use App\Http\Requests\UpdateBookRequest;
use App\Models\Author;
use App\Models\Book;
use App\Models\Format;
use App\Models\ReadInstance;
use App\Models\Scopes\BelongsToCurrentUser;
use App\Models\Version;
use App\Services\AuthorService;
use App\Services\BookService;
use App\Services\GenreService;
use App\Support\BookCreator;
use App\Support\BookListing;
use App\Support\Slugger;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BookController extends Controller
{
    protected $bookService;

    protected $genreService;

    protected $authorService;

    public function __construct(
        BookService $bookService,
        GenreService $genreService,
        AuthorService $authorService
    ) {
        $this->bookService = $bookService;
        $this->genreService = $genreService;
        $this->authorService = $authorService;
    }

    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index(Request $request)
    {
        $query = BookListing::query();

        if ($request->filled('search')) {
            $this->applySearchFilter($query, (string) $request->input('search'));
        }

        if ($request->has('format')) {
            $format = $request->get('format');
            $query->whereHas('versions.format', function ($q) use ($format) {
                $q->where('formats.name', $format);
            });
        }

        $this->applyDiscardedFilter($query, $request);

        // Unrecognized keys fall back to the default rather than erroring — a
        // stale bookmark should render the library, not a 422.
        BookListing::sort($query, $request->input('sort'), $request->input('direction'));

        // Determine the pagination size, default to 20 if not specified
        $pageSize = $request->input('limit', 20);

        // Paginate the results
        $books = $query->paginate($pageSize);

        $formattedBooks = $this->bookService->getBooksList(collect($books->items()));

        // Return paginated results
        return response()->json([
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
     * Match `?search=` against the title or either half of an author's name.
     *
     * Author matching goes through `whereHas` rather than a join so the query
     * keeps its one-row-per-book shape — see {@see BookListing::query()}.
     */
    private function applySearchFilter($query, string $search): void
    {
        // `%` and `_` are wildcards to LIKE, so an unescaped term matches
        // arbitrarily. Bound parameters make this injection-safe either way;
        // escaping is what makes the search return what it looks like it will.
        $term = '%'.addcslashes($search, '%_\\').'%';

        $query->where(function ($q) use ($term) {
            $q->where('books.title', 'like', $term)
                ->orWhereHas('authors', function ($a) use ($term) {
                    $a->where('authors.first_name', 'like', $term)
                        ->orWhere('authors.last_name', 'like', $term);
                });
        });
    }

    /**
     * Constrain a books query by whether the book is still on the shelf.
     *
     * The rule itself lives on the model — see `Book::scopeOnShelf()` and
     * `Book::scopeFullyDiscarded()`. This method only maps the query string
     * onto it: `?discarded=` accepts `exclude` (default), `only`, or `all`,
     * with `0`/`false` and `1`/`true` as aliases for the first two.
     */
    private function applyDiscardedFilter($query, Request $request): void
    {
        $mode = $this->normalizeDiscardedMode($request->input('discarded'));

        match ($mode) {
            'all' => null,
            'only' => $query->fullyDiscarded(),
            default => $query->onShelf(),
        };
    }

    private function normalizeDiscardedMode($value): string
    {
        if ($value === null || $value === '') {
            return 'exclude';
        }

        return match (strtolower((string) $value)) {
            'all' => 'all',
            'only', '1', 'true' => 'only',
            default => 'exclude',
        };
    }

    public function getBooksByFormat(Request $request)
    {
        // Get format parameter from request
        $formatName = $request->input('format');

        // Fetch the ID of the specified format
        $format = Format::where('slug', $formatName)->first();

        if (! $format) {
            return response()->json(['message' => 'Format not found'], 404);
        }

        Log::info(Book::whereHas('versions', function ($query) use ($format) {
            $query->where('format_id', $format->id);
        })->toSql());

        // Fetch paginated books that have a version matching the given format ID
        $books = Book::whereHas('versions', function ($query) use ($format) {
            $query->where('format_id', $format->id);
        })->paginate(20);

        return response()->json($books);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return Response
     */
    public function store(StoreBookRequest $request)
    {
        $book = BookCreator::create($request->title(), $request->authors());

        $new_authors = $this->authorService->attachToBook($book, $request->authors());
        $new_versions = $this->prepareVersions($request->versions());

        $book->versions()->saveMany($new_versions);

        $new_genres = $this->genreService->attachByName($book, $request->genreNames());

        $new_read_instances = [];
        $readInstancesData = $request->readInstances();

        if ($readInstancesData !== []) {
            $new_read_instances = $this->updateReadInstances($book, $readInstancesData);
        }

        return $this->buildResponse($book, $new_authors, $new_versions, $new_genres, $new_read_instances);
    }

    /**
     * Display the specified resource.
     *
     * @param  Book  $book
     * @return Response
     */
    public function show($book_id)
    {
        $response = $this->bookService->getBookWithRelations($book_id, 'id');

        return response()->json($response);
    }

    public function getOneBookFromSlug($slug)
    {
        $response = $this->bookService->getBookWithRelations($slug, 'slug');

        return response()->json($response);
    }

    /**
     * Helper functions for update
     */
    private function updateBook($existing_book, string $title)
    {
        try {
            // Update book properties
            $existing_book->fill([
                'title' => $title,
                'slug' => $this->slugForRename($existing_book, $title),
            ])->save();

            // Return a successful response
            return ['book' => $existing_book];
        } catch (\Exception $e) {
            // Return an error response if something goes wrong
            return ['error' => 'An error occurred while updating the book details. '.$e->getMessage()];
        }
    }

    /**
     * The slug a renamed book keeps or moves to.
     *
     * A cosmetic edit — casing, punctuation, whitespace — slugs to the same
     * base as before, and the book keeps whatever slug it has, so the URL of
     * a disambiguated book (`ariel-rodo`) doesn't collapse back onto the
     * `ariel` another book holds. A real retitle goes through the same
     * collision rules as a create, with the book's own row excluded.
     */
    private function slugForRename(Book $book, string $title): string
    {
        if (Slugger::for($title) === Slugger::for($book->title)) {
            return $book->slug;
        }

        $authors = $book->authors()->get()
            ->map(fn ($a) => $a->only(['first_name', 'last_name']))
            ->all();

        return BookCreator::slugFor($title, $authors, $book);
    }

    /**
     * A row carrying an `author_id` renames that author in place; a row without
     * one resolves to an author and joins the book.
     *
     * Both halves are `AuthorService`'s — see its class docblock for why the
     * find-or-create rules live in one place. Note that nothing here detaches:
     * removing an author from a book has no door yet.
     */
    private function updateAuthors($existing_book, $patch_authors)
    {
        $updated_authors = [];
        $newEntries = [];

        foreach ($patch_authors as $index => $author) {
            if (isset($author['author_id'])) {
                $updated_authors[$index] = $this->authorService->rename(
                    Author::findOrFail($author['author_id']),
                    $author,
                );

                continue;
            }

            $newEntries[$index] = $author;
        }

        // One call rather than one per author: `attachToBook` reads the book's
        // current max ordinal to continue from, so attaching in a loop would
        // re-read it each time and stack co-authors onto the same number.
        $resolved = $this->authorService->attachToBook($existing_book, array_values($newEntries));

        foreach (array_keys($newEntries) as $position => $index) {
            $updated_authors[$index] = $resolved[$position];
        }

        ksort($updated_authors);

        return array_values($updated_authors);
    }

    private function updateVersions($existing_book, $patch_versions)
    {
        $existing_versions = $existing_book->versions()->get();
        $updated_versions = [];

        foreach ($patch_versions as $patch_version) {
            if (isset($patch_version['version_id'])) {
                // Update existing version
                $existing_version = $existing_versions->firstWhere('version_id', $patch_version['version_id']);

                if ($existing_version) {
                    $format = Format::findOrFail($patch_version['format']);

                    $existing_version->fill([
                        'format_id' => $format->format_id,
                        'nickname' => $patch_version['nickname'] ?? null,
                    ] + $format->lengthFieldsFrom($patch_version))->save();
                }
            } else {
                // Prepare and save the new version as part of the update process
                $prepared_versions = $this->prepareVersions([$patch_version]);

                foreach ($prepared_versions as $prepared_version) {
                    $existing_book->versions()->save($prepared_version);
                    $updated_versions[] = $prepared_version;
                }

                continue;
            }

            $updated_versions[] = $existing_version;
        }

        foreach ($updated_versions as $version) {
            $version->load('format');
        }

        return $updated_versions;
    }

    private function updateReadInstances($existing_book, $readInstancesData)
    {
        $updated_read_instances = [];

        try {
            foreach ($readInstancesData as $instanceData) {
                // Dates arrive normalized to Y-m-d by the FormRequest — see
                // the NormalizesReadDates concern — so no parsing here.
                if (isset($instanceData['read_instance_id']) && $instanceData['read_instance_id'] != null) {
                    // Scoped to the current user by BelongsToCurrentUser, so
                    // another account's read_instance_id is a 404 here.
                    $existing_read_instance = ReadInstance::findOrFail($instanceData['read_instance_id']);
                    $existing_read_instance->update([
                        'date_read' => $instanceData['date_read'] ?? null,
                        'rating' => $instanceData['rating'] ?? null,
                    ]);
                    $updated_read_instances[] = $existing_read_instance;
                } else {
                    // Create new read instance
                    $new_read_instance = new ReadInstance([
                        'user_id' => auth()->id(),
                        'book_id' => $existing_book->book_id,
                        'date_read' => $instanceData['date_read'] ?? null,
                        'rating' => $instanceData['rating'] ?? null,
                    ]);
                    $existing_book->readInstances()->save($new_read_instance);
                    $updated_read_instances[] = $new_read_instance;
                }
            }

            return ['success' => true, 'readInstances' => $updated_read_instances]; // Return a success response with data
        } catch (\Exception $e) {
            // Return an error response if something goes wrong
            return ['error' => 'An error occurred while updating read instances. '.$e->getMessage()];
        }
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  Book  $book
     * @return Response
     */
    public function update(UpdateBookRequest $request, $id)
    {
        DB::beginTransaction();
        try {
            $existing_book = Book::findOrFail($id);

            // Update book details
            $bookUpdateResponse = $this->updateBook($existing_book, $request->title());
            if (isset($bookUpdateResponse['error'])) {
                throw new \Exception($bookUpdateResponse['error']);
            }

            // Update authors
            if ($request->authors() !== []) {
                $this->updateAuthors($existing_book, $request->authors());
            }

            // Update genres. Unconditional, unlike authors and versions above:
            // an edit that names no genres is an instruction to clear them.
            $this->genreService->syncFromInput($existing_book, $request->genres());

            // Update read instances (existing ones only — no UI to add new instances from edit view)
            $existingInstances = $request->existingReadInstances();
            if ($existingInstances !== []) {
                $readInstancesResponse = $this->updateReadInstances($existing_book, $existingInstances);
                if (isset($readInstancesResponse['error'])) {
                    throw new \Exception($readInstancesResponse['error']);
                }
            }

            // Update versions
            if ($request->versions() !== []) {
                $this->updateVersions($existing_book, $request->versions());
            }

            DB::commit();

            return response()->json($this->bookService->getBookWithRelations($existing_book->book_id));
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error updating book: '.$e->getMessage());

            // The message is logged above, not returned — a raw exception
            // string is an information leak and was never actionable.
            return response()->json(['error' => 'An error occurred while updating the book.'], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  Book  $book
     * @return Response
     */
    public function destroy($book_id)
    {
        return DB::transaction(function () use ($book_id) {

            $existingBook = Book::with('authors.books')->findOrFail($book_id);

            $authors = $existingBook->authors;

            // Every account's reads, not just the deleter's. Deleting a book
            // is a catalog operation; leaving the other account's rows behind
            // would orphan them against a book_id that no longer exists.
            ReadInstance::withoutGlobalScope(BelongsToCurrentUser::class)
                ->where('book_id', $book_id)
                ->delete();
            Version::where('book_id', $book_id)->delete();

            $existingBook->delete();

            $authorsToBeDeleted = [];

            foreach ($authors as $author) {
                $author->load('books');
                if ($author->books->count() == 0) {
                    $fullName = $author->first_name.' '.$author->last_name;
                    $authorsToBeDeleted[] = $fullName;
                    $author->delete();
                }
            }

            return response()->json([
                'message' => 'Book deleted successfully',
                'deleted_authors' => $authorsToBeDeleted,
            ]);
        });
    }

    public function getCompletedYears()
    {
        return response()->json($this->bookService->getAvailableYears());
    }

    public function getBooksByYear($year)
    {
        $books = $this->bookService->getCompletedItemsForYear($year);

        return response()->json($books);
    }

    /**
     * Log a read of a copy the user owns.
     *
     * Every precondition this used to check by hand — the book and version
     * exist, the version is a copy of that book, the rating is on the 0.5
     * scale — is now `StoreReadInstanceRequest`, which reports them together
     * as one 422 instead of one at a time.
     */
    public function addReadInstance(StoreReadInstanceRequest $request)
    {
        $read_instance = DB::transaction(function () use ($request) {
            // One save, not two. Saving through both relations wrote the same
            // row twice (Eloquent deduped the second into an update), which
            // left a window where the first had landed and the second hadn't.
            return ReadInstance::create($request->readInstance() + [
                'user_id' => auth()->id(),
            ]);
        });

        return response()->json($read_instance);
    }

    /**
     * Helper functions
     */
    private function prepareVersions($versions_data)
    {
        $new_versions = [];

        foreach ($versions_data as $version_data) {
            // The format is validated to exist before we get here; a missing
            // one used to be skipped silently, producing a copy-less book.
            $format = Format::findOrFail($version_data['format']);

            $new_version = new Version;
            $new_version['format_id'] = $format->format_id;
            $new_version['nickname'] = $version_data['nickname'] ?? null;

            foreach ($format->lengthFieldsFrom($version_data) as $field => $value) {
                $new_version[$field] = $value;
            }

            $new_version->load('format');
            $new_versions[] = $new_version;
        }

        return $new_versions;
    }

    /**
     * Genres are attached separately, by `GenreService::attachByName`.
     */
    private function buildResponse($book, $authors, $versions, $genres, $readInstances = [])
    {
        $nestedResponse = [
            'book' => $book,
            'authors' => $authors,
            'versions' => $versions,
            'genres' => $genres,
            'readInstances' => $readInstances,
        ];

        return response()->json($nestedResponse);
    }
}
