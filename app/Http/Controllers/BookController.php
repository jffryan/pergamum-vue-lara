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
use App\Services\BookService;
use App\Services\GenreService;
use App\Support\BookCreator;
use App\Support\Slugger;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BookController extends Controller
{
    /**
     * Columns the library listing can be ordered by.
     *
     * Keys are the public `?sort=` values; values are what reaches `ORDER BY`.
     * Everything but `title` resolves to a subquery alias added by
     * {@see libraryQuery()}. Whitelisted rather than passed through because
     * the value lands in the query as an identifier, not a bound parameter.
     */
    private const SORTABLE = [
        'title' => 'books.title',
        'author' => 'sort_author',
        'format' => 'sort_format',
        'pages' => 'sort_pages',
        'date_read' => 'sort_date_read',
        'rating' => 'sort_rating',
    ];

    private const DEFAULT_SORT = 'author';

    protected $bookService;

    protected $genreService;

    public function __construct(BookService $bookService, GenreService $genreService)
    {
        $this->bookService = $bookService;
        $this->genreService = $genreService;
    }

    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index(Request $request)
    {
        $query = $this->libraryQuery();

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
        $this->applySort($query, $request);

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
     * The library listing query, with one sortable value selected per column.
     *
     * Each sort dimension is a correlated subquery rather than a join. The
     * join-and-GROUP-BY shape this replaced had to collapse the row explosion
     * with `MIN(authors.last_name)`, which meant every added select had to
     * join the `GROUP BY` too — a standing trap under MySQL 8's
     * `ONLY_FULL_GROUP_BY`. Subqueries produce one row per book to begin with,
     * so there is nothing to group and each new sortable column is one more
     * entry here.
     *
     * The read-derived subqueries matter for a second reason: they are
     * Eloquent builders, so `BelongsToCurrentUser` applies inside them. The
     * `leftJoin('read_instances', …)` they replaced was raw, and a global
     * scope constrains a model's own queries rather than a join against its
     * table — so it silently ordered and counted against every account's
     * reads. "Date read" and "rating" are only meaningful per-user, so the
     * feature and the fix are the same change.
     *
     * Eager loads are ordered to match: the row renders `authors[0]`,
     * `versions[0]` and `readInstances[0]`, so the value shown in a column has
     * to be the value sorted on, or sorting looks broken on any book with more
     * than one of something.
     */
    private function libraryQuery()
    {
        return Book::query()
            ->with([
                'authors' => fn ($q) => $q->orderBy('book_author.author_ordinal'),
                'versions' => fn ($q) => $q->orderBy('versions.version_id'),
                'versions.format',
                'genres',
                'readInstances' => fn ($q) => $q->orderByDesc('date_read'),
            ])
            ->select('books.*')
            ->addSelect([
                'sort_author' => Author::select('authors.last_name')
                    ->join('book_author', 'book_author.author_id', '=', 'authors.author_id')
                    ->whereColumn('book_author.book_id', 'books.book_id')
                    ->orderBy('book_author.author_ordinal')
                    ->orderBy('authors.last_name')
                    ->limit(1),
                'sort_format' => Format::select('formats.name')
                    ->join('versions', 'versions.format_id', '=', 'formats.format_id')
                    ->whereColumn('versions.book_id', 'books.book_id')
                    ->orderBy('versions.version_id')
                    ->limit(1),
                'sort_pages' => Version::select('versions.page_count')
                    ->whereColumn('versions.book_id', 'books.book_id')
                    ->orderBy('versions.version_id')
                    ->limit(1),
                // Both read-derived sorts resolve against the *most recent*
                // read, so "sort by rating" ranks by how you rated it last
                // rather than by a best-ever the row never displays.
                'sort_date_read' => ReadInstance::select('read_instances.date_read')
                    ->whereColumn('read_instances.book_id', 'books.book_id')
                    ->orderByDesc('read_instances.date_read')
                    ->limit(1),
                // Ratings are stored doubled (see ReadInstance's accessor).
                // Doubling is monotonic, so ordering on the raw column is
                // correct — this value is never rendered, only sorted on.
                'sort_rating' => ReadInstance::select('read_instances.rating')
                    ->whereColumn('read_instances.book_id', 'books.book_id')
                    ->orderByDesc('read_instances.date_read')
                    ->limit(1),
            ]);
    }

    /**
     * Apply `?sort=` / `?direction=`, falling back to the default on anything
     * unrecognized rather than erroring — a stale bookmark should render the
     * library, not a 422.
     */
    private function applySort($query, Request $request): void
    {
        $key = strtolower((string) $request->input('sort', self::DEFAULT_SORT));
        $column = self::SORTABLE[$key] ?? self::SORTABLE[self::DEFAULT_SORT];

        $direction = strtolower((string) $request->input('direction')) === 'desc' ? 'desc' : 'asc';

        // MySQL sorts NULL first ascending, which would head a "by rating"
        // list with every book you have never read. Absent values go last in
        // both directions instead.
        $query->orderByRaw("({$column} is null) asc")
            ->orderBy($column, $direction)
            // No sort key here is unique, and pagination over a non-unique
            // ordering lets rows swap between pages. Break the tie on the PK.
            ->orderBy('books.book_id', 'asc');
    }

    /**
     * Match `?search=` against the title or either half of an author's name.
     *
     * Author matching goes through `whereHas` rather than a join so the query
     * keeps its one-row-per-book shape — see {@see libraryQuery()}.
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
        $book = BookCreator::create($request->title());

        $new_authors = $this->handleAuthors($request->authors());
        $new_versions = $this->prepareVersions($request->versions());

        $this->attachModels($book, $new_authors, $new_versions);

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
                'slug' => Slugger::for($title),
            ])->save();

            // Return a successful response
            return ['book' => $existing_book];
        } catch (\Exception $e) {
            // Return an error response if something goes wrong
            return ['error' => 'An error occurred while updating the book details. '.$e->getMessage()];
        }
    }

    private function updateAuthors($existing_book, $patch_authors)
    {
        $updated_authors = [];

        foreach ($patch_authors as $author) {
            if (isset($author['author_id'])) {
                $existing_author = Author::findOrFail($author['author_id']);
                $existing_author->update($author);
            } else {
                $firstName = $author['first_name'] ?? '';
                $lastName = $author['last_name'] ?? '';
                $slug = Slugger::for(trim("$firstName $lastName"));

                $existing_author = Author::firstOrCreate(
                    ['slug' => $slug, 'first_name' => $firstName, 'last_name' => $lastName]
                );
                $existing_book->authors()->attach($existing_author);
            }
            $updated_authors[] = $existing_author;
        }

        return $updated_authors;
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
    private function handleAuthors($authorsData)
    {
        return collect($authorsData)->map(function ($author) {
            $firstName = $author['first_name'] ?? '';
            $lastName = $author['last_name'] ?? '';
            $author['slug'] = Slugger::for(trim("$firstName $lastName"));

            return Author::firstOrCreate($author);
        })->all();
    }

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
    private function attachModels($book, $authors, $versions)
    {
        $authorIds = array_map(function ($author) {
            return $author->author_id;
        }, $authors);

        $book->authors()->attach($authorIds);
        $book->versions()->saveMany($versions);
    }

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
