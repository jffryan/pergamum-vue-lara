<?php

namespace App\Http\Controllers;

use App\Models\Author;
use App\Models\Book;
use App\Models\Format;
use App\Models\Genre;
use App\Models\Version;
use Illuminate\Http\Request;
use Carbon\Carbon;

class BookController extends Controller
{
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

        $formats = Format::all();
        $data = [

            "formats" => $formats,
        ];


        return response()->json($data);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        $bookForm = $request->bookForm;

        // BOOK
        $new_book = new Book;
        $new_book->title =  $bookForm["book"]["title"];
        $new_book->is_completed =  $bookForm["book"]["is_completed"];
        if ($new_book->is_completed === true) {
            $new_book->rating =  $bookForm["book"]["rating"];
            $new_book->date_completed = Carbon::createFromFormat("Y-m-d", $bookForm["book"]["date_completed"]);
        } else {
            $new_book->rating =  null;
            $new_book->date_completed =  null;
        }
        $new_book->save();

        // AUTHOR
        $authors_array = $bookForm["authors"];
        $new_authors = [];
        foreach ($authors_array as $author) {
            $new_author = Author::firstOrCreate(
                [
                    "first_name"    => $author["first_name"],
                    "last_name"     => $author["last_name"],
                ],
            );
            array_push($new_authors, $new_author);
        };


        // VERSION
        $new_version = new Version;
        $new_version->page_count =  $bookForm["version"]["page_count"];
        $new_version->format_id =  $bookForm["version"]["format"];

        // GENRES
        $genres_array = $bookForm["book"]["genres"]["parsed"];
        $new_genres = [];
        foreach ($genres_array as $genre) {
            $genre_request = Genre::firstOrCreate(
                ["name" => $genre],
            );
            array_push($new_genres, $genre_request);
        };


        // JOIN MODELS
        foreach ($new_authors as $new_author) {
            $new_book->authors()->attach($new_author);
        }
        $new_book->versions()->save($new_version);
        foreach ($new_genres as $new_genre) {
            $new_book->genres()->attach($new_genre);
        }

        // RESPONSE
        $data = [
            "book"      => $new_book,
            "author"    => $new_authors,
            "version"   => $new_version,
            "genres"    => $new_genres,
        ];
        return response()->json($data);
    }

    /**
     * Display the specified resource.
     *
     * @param  \App\Models\Book  $book
     * @return \Illuminate\Http\Response
     */
    public function show($id)
    {
        return Book::with("authors", "versions", "versions.format", "genres")->where("book_id", $id)->get();
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
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  \App\Models\Book  $book
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        // THIS FUNCTION IS INSANE
        $existing_book = Book::findOrFail($id);

        if ($existing_book) {
            $existing_authors = $existing_book->authors()->get();
            $existing_versions = $existing_book->versions()->get();
        }

        $patch_book = $request->data["book_form"]["book"];
        $patch_authors = $request->data["book_form"]["authors"];
        $patch_version = $request->data["book_form"]["versions"][0];
        $genres_array = $request->data["book_form"]["newGenres"]["formatted"];

        // BOOK
        if ($existing_book) {
            $existing_book->title = $patch_book["title"];
            $existing_book->is_completed = $patch_book["is_completed"];
            if ($patch_book["is_completed"] === true) {
                $existing_book->rating = $patch_book["rating"];
                $existing_book->date_completed = Carbon::createFromFormat("Y-m-d", $patch_book["date_completed"]);
            } else {
                $existing_book->rating = null;
                $existing_book->date_completed = null;
            }

            $existing_book->save();
        }

        // If we already have authors
        if ($existing_authors) {

            // THIS DOESN'T WORK because we want to loop through PATCH authors, not EXISTING authors
            foreach ($patch_authors as $key => $author) {
                // Check if the request already has an ID attached
                if (array_key_exists("author_id", $author)) {
                    $fetch_id = $author["author_id"];
                    // Find existing author
                    $existing_author = Author::findOrFail($fetch_id);

                    // If we get a valid response from the DB, patch it
                    if ($existing_author) {
                        $existing_author->first_name = $patch_authors[$key]["first_name"];
                        $existing_author->last_name = $patch_authors[$key]["last_name"];
                        $existing_author->save();
                    } else {
                        // we have an ID but no record? ERROR!
                        // @TODO: Handle gracefully
                        return "ERROR, AUTHOR NOT FOUND";
                    }
                } else // If no ID key exists, it must be a new author... right?
                {

                    $new_author = new Author;
                    $new_author->first_name =  $patch_authors[$key]["first_name"];
                    $new_author->last_name =  $patch_authors[$key]["last_name"];

                    $new_author->save();
                    $existing_book->authors()->attach($new_author);
                }
            }
        }


        // VERSIONS
        if ($existing_versions) {
            $fetch_id = $existing_versions[0]["version_id"];
            $existing_version = Version::findOrFail($fetch_id);
            // Catch error
            if ($existing_version) {
                $existing_version->page_count = $patch_version["page_count"];
                $existing_version->format_id = $patch_version["format_id"];
                $existing_version->save();
            }
        }

        // NEW GENRES
        $new_genres = [];
        foreach ($genres_array as $genre) {
            $genre_request = Genre::firstOrCreate(
                ["name" => $genre],
            );
            array_push($new_genres, $genre_request);
        };
        foreach ($new_genres as $new_genre) {
            $existing_book->genres()->attach($new_genre);
        }


        // RESPONSE
        $data = [
            "book"          => $existing_book,
            "authors"       => $existing_authors,
            "versions"      => $existing_versions,
            "genres"        => $new_genres,
        ];
        return response()->json($data);
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
