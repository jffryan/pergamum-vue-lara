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

class BookController extends Controller
{
    /**
     * Helper functions
     * 
     */

    private function createBook($bookData)
    {
        $slug = Str::of($bookData['title'])
        ->lower()
        ->replaceMatches('/[^a-z0-9\s]/', '')  // Remove non-alphanumeric characters
        ->replace(' ', '-')  // Replace spaces with hyphens
        ->limit(30);  // Limit to 30 characters

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
            return Author::firstOrCreate($author);
        })->all();
    }
    private function prepareVersions($versions_data) {
        $new_versions = [];

        foreach($versions_data as $version_data) {
            $new_version = new Version;
            $format = Format::find($version_data['format']);

            if ($format->name == 'Audio') {
                // Validate elsewhere
            } elseif ($format->name == 'Paper') {
                // Nullify audio_runtime for paper format
                $new_version['audio_runtime'] = null;
            }

            $new_version['page_count'] = $version_data['page_count'];
            $new_version['audio_runtime'] = $version_data['audio_runtime'];
            $new_version['format_id'] = $version_data['format'];
            $new_version['nickname'] = $version_data['nickname'];

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
    private function buildResponse($book, $authors, $version, $genres)
    {
        return response()->json([
            'book' => $book,
            'authors' => $authors,
            'versions' => $version,
            'genres' => $genres,
        ]);
    }


    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        return Book::with("authors", "versions", "versions.format", "genres")->get();
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

        $new_book = $this->createBook($bookForm["book"]);
        $new_authors = $this->handleAuthors($bookForm["authors"]);
        $new_versions = $this->prepareVersions($bookForm["versions"]);
        $new_genres = $this->handleGenres($bookForm["book"]["genres"]["parsed"]);

        $this->attachModels($new_book, $new_authors, $new_versions, $new_genres);

        return $this->buildResponse($new_book, $new_authors, $new_versions, $new_genres);
    }


    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Book  $book
     * @return \Illuminate\Http\Response
     */
    public function show($slug)
    {
        return Book::with("authors", "versions", "versions.format", "genres")->where("book_id", $slug)->firstOrFail();
    }

    public function getBookBySlug($slug)
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
    private function updateVersion($existing_book, $patch_version)
    {
        $existing_versions = $existing_book->versions()->get();
        $existing_version = Version::findOrFail($existing_versions[0]['version_id']);

        $existing_version->fill([
            'page_count' => $patch_version['page_count'],
            'format_id' => $patch_version['format_id'],
        ])->save();

        return [$existing_version];
    }

    private function updateGenres($existing_book, $genres_array)
    {
        $new_genres = collect($genres_array)->map(function ($genre) {
            return Genre::firstOrCreate(['name' => $genre]);
        })->all();

        $existing_book->genres()->syncWithoutDetaching($new_genres);

        return $new_genres;
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
        $genres_array = $data["genres"]["parsed"];

        $this->updateBook($existing_book, $patch_book);
        $existing_authors = $this->updateAuthors($existing_book, $patch_authors);
        $existing_versions = $this->updateVersion($existing_book, $patch_versions);
        $new_genres = $this->updateGenres($existing_book, $genres_array);

        return $this->buildResponse($existing_book, $existing_authors, $existing_versions, $new_genres);
    }

    /**
     * Remove genre instance from book
     * @param  \Illuminate\Http\Request  $request
     */
    public function remove_genre_instance(Request $request)
    {
        $book_to_detach = Book::find($request["request"]["book_id"]);
        $genre_to_detach = Genre::find($request["request"]["genre_id"]);
        if ($book_to_detach && $genre_to_detach) {
            $book_to_detach->genres()->detach($genre_to_detach);
            $genre_to_detach->books()->detach($book_to_detach);
            return [
                "status" => "success",
                "message" => "Operation completed successfully",
            ];
        } else {
            return "Nothing here...";
        }
    }

    /**
     * Remove author instance from book
     * @param  \Illuminate\Http\Request  $request
     */
    public function remove_author_instance(Request $request)
    {
        $book_to_detach = Book::find($request["request"]["book_id"]);
        $author_to_detach = Author::find($request["request"]["author_id"]);
        if ($book_to_detach && $author_to_detach) {
            $book_to_detach->genres()->detach($author_to_detach);
            $author_to_detach->books()->detach($book_to_detach);
            return [
                "status" => "success",
                "message" => "Operation completed successfully",
            ];
        } else {
            return "Nothing here...";
        }
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  \App\Models\Book  $book
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        $existingBook = Book::find($id);

        if ($existingBook) {
            $existingBook->delete();
            return "Book deleted successfully";
        }

        return "Book not found";
    }
}
