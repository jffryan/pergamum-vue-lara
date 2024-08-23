<?php

namespace App\Http\Controllers;

use App\Models\Author;
use App\Models\Book;
use App\Models\Format;
use App\Models\Genre;
use App\Models\Version;
use App\Models\BacklogItem;
use App\Models\ReadInstance;
use App\Services\BookService;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;


class BookController extends Controller
{

    protected $bookService;

    public function __construct(BookService $bookService)
    {
        $this->bookService = $bookService;
    }

    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */

    public function index(Request $request)
    {
        if ($request->has('search')) {
            return $this->searchBooks($request);
        }

        $query = Book::with("authors", "versions", "versions.format", "genres", "readInstances")
            ->selectRaw('books.book_id, books.title, books.slug, MIN(authors.last_name) as primary_author_last_name')
            ->leftJoin('book_author', 'books.book_id', '=', 'book_author.book_id')
            ->leftJoin('authors', 'authors.author_id', '=', 'book_author.author_id')
            ->leftJoin('read_instances', 'books.book_id', '=', 'read_instances.book_id')
            ->groupBy('books.book_id', 'books.title', 'books.slug');

        if ($request->has('format')) {
            $format = $request->get('format');
            $query->whereHas('versions.format', function ($q) use ($format) {
                $q->where('formats.name', $format);
            });
        }

        $query->orderBy('primary_author_last_name', 'asc');

        // Determine the pagination size, default to 30 if not specified
        $pageSize = $request->input('limit', 20);

        // Paginate the results
        $books = $query->paginate($pageSize);

        $formattedBooks = $this->bookService->getBooksList(collect($books->items()));

        // Return paginated results
        return response()->json([
            'books' =>  $formattedBooks,
            'pagination' => [
                'total' => $books->total(),
                'perPage' => $books->perPage(),
                'currentPage' => $books->currentPage(),
                'lastPage' => $books->lastPage(),
                'from' => $books->firstItem(),
                'to' => $books->lastItem()
            ]
        ]);
    }

    public function getBooksByFormat(Request $request)
    {
        // Get format parameter from request
        $formatName = $request->input('format');

        // Fetch the ID of the specified format
        $format = Format::where('slug', $formatName)->first();

        if (!$format) {
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
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $bookForm = $request->book;

        $book = $this->createOrGetBook($bookForm["book"]);

        if (!$book->wasRecentlyCreated) {
            // If the book was not recently created (i.e., it existed), we'll just add a new version
            $new_versions = $this->prepareVersions($bookForm["versions"]);
            $book->versions()->saveMany($new_versions);

            return $this->buildResponse($book, [], $new_versions, []);
        }

        $new_authors = $this->handleAuthors($bookForm["authors"]);
        $new_versions = $this->prepareVersions($bookForm["versions"]);
        $new_genres = $this->handleGenres($bookForm["book"]["genres"]["parsed"]);

        $this->attachModels($book, $new_authors, $new_versions, $new_genres);

        $new_read_instances = [];

        if (isset($bookForm["readInstances"])) {
            $readInstancesData = array_filter($bookForm["readInstances"], function ($instance) {
                return !empty($instance["date_read"]);
            });

            $new_read_instances = $this->updateReadInstances($book, $readInstancesData);
        }

        // Check if the book should be added to the backlog
        if ($bookForm["book"]["is_backlog"]) {
            // Add the book to the backlog. Determine the order as needed.
            $order = BacklogItem::max('backlog_ordinal') + 1;
            $book->addToBacklog($order);
        }

        return $this->buildResponse($book, $new_authors, $new_versions, $new_genres, $new_read_instances);
    }

    public function bulkCreate(Request $request)
    {
        $booksForm = $request->input('books');

        $responses = [];

        DB::beginTransaction();

        try {
            foreach ($booksForm as $bookForm) {
                $new_book = $this->createOrGetBook($bookForm["book"]);
                $new_authors = $this->handleAuthors($bookForm["authors"]);
                $new_versions = $this->prepareVersions($bookForm["versions"]);
                $new_genres = $this->handleGenres($bookForm["book"]["genres"]["parsed"]);

                $this->attachModels($new_book, $new_authors, $new_versions, $new_genres);

                $responses[] = $this->buildResponse($new_book, $new_authors, $new_versions, $new_genres);
            }

            DB::commit();
        } catch (\Exception $e) {
            DB::rollBack();
            return response()->json(['message' => 'An error occurred', 'error' => $e->getMessage()], 500);
        }

        return response()->json(['books' => $responses]);
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Book  $book
     * @return \Illuminate\Http\Response
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
     * 
     */
    private function updateBook($existing_book, $patch_book)
    {
        try {
            $slug = Str::of($patch_book['title'])
                ->lower()
                ->replaceMatches('/[^a-z0-9\s]/', '')  // Remove non-alphanumeric characters
                ->replace(' ', '-')  // Replace spaces with hyphens
                ->limit(30);  // Limit to 30 characters

            // Update book properties
            $existing_book->fill([
                'title' => $patch_book['title'],
                'slug' => $slug,
            ])->save();

            // Return a successful response
            return ['book' => $existing_book];
        } catch (\Exception $e) {
            // Return an error response if something goes wrong
            return ['error' => 'An error occurred while updating the book details. ' . $e->getMessage()];
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
                $existing_author = Author::create($author);
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
                    $existing_version->fill([
                        'page_count' => $patch_version['page_count'],
                        'format_id' => $patch_version['format'],
                        'nickname' => $patch_version['nickname'],
                        'audio_runtime' => $patch_version['audio_runtime'] ?? null,
                    ])->save();
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

    private function updateGenres($existing_book, $genres_array)
    {
        $new_genres = array_map(function ($genre) {
            return Genre::firstOrCreate(['name' => $genre])->genre_id;
        }, $genres_array);

        $existing_book->genres()->sync($new_genres);

        $new_genre_instances = Genre::findMany($new_genres);

        return $new_genre_instances;
    }

    private function updateReadInstances($existing_book, $readInstancesData)
    {
        $updated_read_instances = [];

        try {
            foreach ($readInstancesData as $instanceData) {
                if (isset($instanceData['read_instances_id']) && $instanceData['read_instances_id'] != null) {
                    // Update existing read instance
                    $existing_read_instance = ReadInstance::findOrFail($instanceData['read_instances_id']);
                    $existing_read_instance->update([
                        'date_read' => Carbon::createFromFormat("m/d/Y", $instanceData['date_read']),
                        // Update other fields as necessary
                    ]);
                    $updated_read_instances[] = $existing_read_instance;
                } else {
                    // Create new read instance
                    $new_read_instance = new ReadInstance([
                        'book_id' => $existing_book->book_id,
                        'date_read' => Carbon::createFromFormat("m/d/Y", $instanceData['date_read']),
                        // Set other fields as necessary
                    ]);
                    $existing_book->readInstances()->save($new_read_instance);
                    $updated_read_instances[] = $new_read_instance;
                }
            }

            return ['success' => true, 'readInstances' => $updated_read_instances]; // Return a success response with data
        } catch (\Exception $e) {
            // Return an error response if something goes wrong
            return ['error' => 'An error occurred while updating read instances. ' . $e->getMessage()];
        }
    }



    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Book  $book
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $existing_book = Book::findOrFail($id);
            $request_data = $request->all();
            $data = $request_data['request']['formData'];

            // Update book details
            $bookUpdateResponse = $this->updateBook($existing_book, $data["book"]);
            if (isset($bookUpdateResponse['error'])) {
                throw new \Exception($bookUpdateResponse['error']);
            }
            /*
            // Update authors
            $authorsUpdateResponse = $this->updateAuthors($existing_book, $formData["authors"]);
            if (isset($authorsUpdateResponse['error'])) {
                throw new \Exception($authorsUpdateResponse['error']);
            }

            // Update versions
            $versionsUpdateResponse = $this->updateVersions($existing_book, $formData["versions"]);
            if (isset($versionsUpdateResponse['error'])) {
                throw new \Exception($versionsUpdateResponse['error']);
            }

            // Update genres
            $genresUpdateResponse = $this->updateGenres($existing_book, $formData["genres"]["parsed"]);
            if (isset($genresUpdateResponse['error'])) {
                throw new \Exception($genresUpdateResponse['error']);
            }

            // Update read instances
            $readInstancesUpdateResponse = $this->updateReadInstances($existing_book, $formData["readInstances"]);
            if (isset($readInstancesUpdateResponse['error'])) {
                throw new \Exception($readInstancesUpdateResponse['error']);
            }
*/
            DB::commit();

            return $this->buildResponse(
                $bookUpdateResponse['book'],
                $data['authors'],
                $data['versions'],
                $data['genres'],
                $data['readInstances']
                // $authorsUpdateResponse,
                // $versionsUpdateResponse,
                // $genresUpdateResponse,
                // $readInstancesUpdateResponse['readInstances']
            );
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error updating book: " . $e->getMessage());

            // Return a dynamic error response based on the exception thrown
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
    /*
    public function update(Request $request, $id)
    {
        DB::beginTransaction();
        try {
            $existing_book = Book::findOrFail($id);
            $data = $request->book;

            // Update book details
            $bookUpdateResponse = $this->updateBook($existing_book, $data["book"]);
            if (isset($bookUpdateResponse['error'])) {
                throw new \Exception($bookUpdateResponse['error']);
            }

            // Update authors
            $authorsUpdateResponse = $this->updateAuthors($existing_book, $data["authors"]);
            if (isset($authorsUpdateResponse['error'])) {
                throw new \Exception($authorsUpdateResponse['error']);
            }

            // Update versions
            $versionsUpdateResponse = $this->updateVersions($existing_book, $data["versions"]);
            if (isset($versionsUpdateResponse['error'])) {
                throw new \Exception($versionsUpdateResponse['error']);
            }

            // Update genres
            $genresUpdateResponse = $this->updateGenres($existing_book, $data["book"]["genres"]["parsed"]);
            if (isset($genresUpdateResponse['error'])) {
                throw new \Exception($genresUpdateResponse['error']);
            }

            // Update read instances
            $readInstancesUpdateResponse = $this->updateReadInstances($existing_book, $data["readInstances"]);
            if (isset($readInstancesUpdateResponse['error'])) {
                throw new \Exception($readInstancesUpdateResponse['error']);
            }

            DB::commit();

            return $this->buildResponse(
                $bookUpdateResponse['book'],
                $authorsUpdateResponse,
                $versionsUpdateResponse,
                $genresUpdateResponse,
                $readInstancesUpdateResponse['readInstances']
            );
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error("Error updating book: " . $e->getMessage());

            // Return a dynamic error response based on the exception thrown
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
*/


    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Book  $book
     * @return \Illuminate\Http\Response
     */
    public function destroy($book_id)
    {
        return DB::transaction(function () use ($book_id) {

            $existingBook = Book::with('authors.books')->findOrFail($book_id);

            $authors = $existingBook->authors;

            $existingBook->delete();

            $authorsToBeDeleted = [];

            foreach ($authors as $author) {
                $author->load('books');
                if ($author->books->count() == 0) {
                    $fullName = $author->first_name . ' ' . $author->last_name;
                    $authorsToBeDeleted[] = $fullName;
                    $author->delete();
                }
            }

            return response()->json([
                'message' => 'Book deleted successfully',
                'deleted_authors' => $authorsToBeDeleted
            ]);
        });
    }
    public function getBooksByYear($year)
    {
        $books = $this->bookService->getBooksByYear($year);
        return response()->json($books);
    }
    public function addReadInstance(Request $request)
    {
        $read_instance_data = $request['readInstance'];
        $book_id = $read_instance_data['book_id'];
        $version_id = $read_instance_data['version_id'];

        $book = Book::findOrFail($book_id);
        $version = Version::findOrFail($version_id);

        if (!$book || !$version) {
            return response()->json(['message' => 'Book or version not found'], 404);
        }

        $read_instance = new ReadInstance($read_instance_data);

        // Attach the read instance to the book and version
        $book->readInstances()->save($read_instance);
        $version->readInstances()->save($read_instance);

        $book->save();

        return response()->json($read_instance);
    }

    /**
     * Helper functions
     * 
     */


    private function createOrGetBook($bookData)
    {
        $slug = Str::of($bookData['title'])
            ->lower()
            ->replaceMatches('/[^a-z0-9\s]/', '')  // Remove non-alphanumeric characters
            ->replace(' ', '-')  // Replace spaces with hyphens
            ->limit(30);  // Limit to 30 characters

        // Look for an existing book by the slug
        $existingBook = Book::where('slug', $slug)->first();

        if ($existingBook) {
            return $existingBook;
        }

        $data = [
            'title' => $bookData['title'],
            'slug' => $slug,
        ];

        return Book::create($data);
    }
    private function handleAuthors($authorsData)
    {
        return collect($authorsData)->map(function ($author) {
            $firstName = isset($author['first_name']) ? $author['first_name'] : '';
            $lastName = isset($author['last_name']) ? $author['last_name'] : '';
            $slugParts = array_filter([$firstName, $lastName]); // Remove null or empty parts
            $slug = implode(' ', $slugParts); // Join with space
            $slug = strtolower($slug); // Convert to lowercase
            $slug = preg_replace('/\s+/', ' ', $slug); // Remove extra spaces
            $slug = str_replace(' ', '-', $slug); // Replace spaces with hyphens

            $author['slug'] = $slug;

            return Author::firstOrCreate($author);
        })->all();
    }
    private function prepareVersions($versions_data)
    {
        $new_versions = [];

        foreach ($versions_data as $version_data) {
            $new_version = new Version;
            $format = Format::find($version_data['format']);

            if (!$format) {
                // Handle error here
                continue;
            }

            $new_version['page_count'] = $version_data['page_count'];
            $new_version['format_id'] = $version_data['format'];
            $new_version['nickname'] = $version_data['nickname'];

            if ($format->name == 'Audio') {
                $new_version['audio_runtime'] = $version_data['audio_runtime'];
            } elseif ($format->name == 'Paper') {
                $new_version['audio_runtime'] = null;
            } else {
                $new_version['audio_runtime'] = $version_data['audio_runtime'];
            }

            $new_version->load('format');
            $new_versions[] = $new_version;
        }

        return $new_versions;
    }

    private function handleGenres($genresData)
    {
        return collect($genresData)->map(function ($genre) {
            return Genre::firstOrCreate(['name' => $genre]);
        })->all();
    }
    private function attachModels($book, $authors, $versions, $genres)
    {
        $authorIds = array_map(function ($author) {
            return $author->author_id;
        }, $authors);

        $genreIds = array_map(function ($genre) {
            return $genre->genre_id;
        }, $genres);

        $book->authors()->attach($authorIds);
        $book->versions()->saveMany($versions);
        $book->genres()->attach($genreIds);
    }
    private function buildResponse($book, $authors, $versions, $genres, $readInstances = [])
    {
        $nestedResponse = [
            'book' => $book,
            'authors' => $authors,
            'versions' => $versions,
            'genres' => $genres,
            'read_instances' => $readInstances
        ];

        return response()->json($nestedResponse);
    }

    private function searchBooks(Request $request)
    {
        $search = $request->search;

        $query = Book::with("authors", "versions", "versions.format", "genres", "readInstances")
            ->selectRaw('books.book_id, books.title, books.slug, MIN(authors.last_name) as primary_author_last_name')
            ->leftJoin('book_author', 'books.book_id', '=', 'book_author.book_id')
            ->leftJoin('authors', 'authors.author_id', '=', 'book_author.author_id')
            ->leftJoin('read_instances', 'books.book_id', '=', 'read_instances.book_id')
            ->where('books.title', 'like', "%$search%")
            ->orWhere('authors.first_name', 'like', "%$search%")
            ->orWhere('authors.last_name', 'like', "%$search%")
            ->groupBy('books.book_id', 'books.title', 'books.slug');

        $query->orderBy('primary_author_last_name', 'asc');

        // Determine the pagination size, default to 30 if not specified
        $pageSize = $request->input('limit', 20);

        // Paginate the results
        $books = $query->paginate($pageSize);

        $formattedBooks = $this->bookService->getBooksList(collect($books->items()));

        // Return paginated results
        return response()->json([
            'books' =>  $formattedBooks,
            'pagination' => [
                'total' => $books->total(),
                'perPage' => $books->perPage(),
                'currentPage' => $books->currentPage(),
                'lastPage' => $books->lastPage(),
                'from' => $books->firstItem(),
                'to' => $books->lastItem()
            ]
        ]);
    }
}
