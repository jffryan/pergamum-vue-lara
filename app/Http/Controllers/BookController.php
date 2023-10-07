<?php

namespace App\Http\Controllers;

use App\Models\Author;
use App\Models\Book;
use App\Models\Format;
use App\Models\Genre;
use App\Models\Version;
use Illuminate\Http\Request;
use Carbon\Carbon;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;


class BookController extends Controller
{
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
             'is_completed' => $bookData['is_completed'],
             'rating' => $bookData['is_completed'] ? $bookData['rating'] : null,
             'date_completed' => $bookData['is_completed'] ? Carbon::createFromFormat("m/d/Y", $bookData['date_completed']) : null,
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
    private function buildResponse($book, $authors, $versions, $genres)
    {
        $nestedResponse = [
            'book' => array_merge(
                $book->toArray(),
                [
                    'authors' => $authors,
                    'versions' => $versions,
                    'genres' => $genres,
                ]
            )
        ];

        return response()->json($nestedResponse);
    }


    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $query = Book::with("authors", "versions", "versions.format", "genres")
            ->selectRaw('books.book_id, books.title, books.slug, books.is_completed, books.rating, books.date_completed, MIN(authors.last_name) as primary_author_last_name')
            ->leftJoin('book_author', 'books.book_id', '=', 'book_author.book_id')
            ->leftJoin('authors', 'authors.author_id', '=', 'book_author.author_id')
            ->groupBy('books.book_id', 'books.title', 'books.slug', 'books.is_completed', 'books.rating', 'books.date_completed');

        if ($request->has('format')) {
            $format = $request->get('format');
            $query->whereHas('versions.format', function ($q) use ($format) {
                $q->where('formats.name', $format);
            });
        }

        if ($request->has('sort_by') && $request->has('sort_order')) {
            $query->orderBy($request->sort_by, $request->sort_order);
        } else {
            $query->orderBy('primary_author_last_name', 'asc');
        }

        $limit = $request->has('limit') ? $request->limit : 20;
        return $query->paginate($limit);
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
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
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

        return $this->buildResponse($book, $new_authors, $new_versions, $new_genres);
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
        return Book::with("authors", "versions", "versions.format", "genres")->where("book_id", $book_id)->firstOrFail();
    }

    public function getOneBookFromSlug($slug)
    {
        $book = Book::with("authors", "versions", "versions.format", "genres")->where("slug", $slug)->firstOrFail();

        return response()->json($book);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  \App\Models\Book  $book
     * @return \Illuminate\Http\Response
     */
    public function edit(Book $book)
    {
        //
    }

    /**
     * Helper functions for update
     * 
     */
    private function updateBook($existing_book, $patch_book)
    {
        $existing_book->fill([
            'title' => $patch_book['title'],
            'is_completed' => $patch_book['is_completed'],
            'rating' => $patch_book['is_completed'] ? $patch_book['rating'] : null,
            'date_completed' => $patch_book['is_completed'] ? Carbon::createFromFormat("m/d/Y", $patch_book['date_completed']) : null,
        ])->save();
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
            $existing_version = $existing_versions->firstWhere('version_id', $patch_version['version_id']);

            if ($existing_version) {
                // Update existing version
                $existing_version->fill([
                    'page_count' => $patch_version['page_count'],
                    'format_id' => $patch_version['format'],
                    'nickname' => $patch_version['nickname'],
                    'audio_runtime' => $patch_version['audio_runtime'] ?? null,
                ])->save();
            } else {
                // Create new version
                $existing_version = new Version;
                $existing_version->fill([
                    'page_count' => $patch_version['page_count'],
                    'format_id' => $patch_version['format_id'],
                    'nickname' => $patch_version['nickname'],
                    'audio_runtime' => $patch_version['audio_runtime'] ?? null,
                ]);
                $existing_book->versions()->save($existing_version);
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

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Book  $book
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        $existing_book = Book::findOrFail($id);
        $data = $request->book;

        $patch_book = $data["book"];
        $patch_authors = $data["authors"];
        $patch_versions = $data["versions"];
        $genres_array = $data["book"]["genres"]["parsed"];

        $this->updateBook($existing_book, $patch_book);
        $existing_authors = $this->updateAuthors($existing_book, $patch_authors);
        $existing_versions = $this->updateVersions($existing_book, $patch_versions);
        $new_genres = $this->updateGenres($existing_book, $genres_array);

        return $this->buildResponse($existing_book, $existing_authors, $existing_versions, $new_genres);
    }

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
}