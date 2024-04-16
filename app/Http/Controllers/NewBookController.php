<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\Book;
use App\Models\Genre;
use App\Models\Author;
use App\Models\Format;
use App\Models\Version;
use App\Models\BacklogItem;
use App\Models\ReadInstance;

class NewBookController extends Controller
{
    //
    public function createOrGetBookByTitle(Request $request)
    {
        $title = $request['title'];

        $slug = Str::of($title)
            ->lower()
            ->replaceMatches('/[^a-z0-9\s]/', '')  // Remove non-alphanumeric characters
            ->replace(' ', '-')  // Replace spaces with hyphens
            ->limit(30);  // Limit to 30 characters

        // Look for an existing book by the slug
        $existingBook = Book::with("authors", "genres", "versions", "versions.format", "versions.readInstances")->where('slug', $slug)->first();

        if ($existingBook) {
            return response()->json(
                [
                    'exists' => true,
                    'book' => $existingBook
                ],
            );
        }

        $data = [
            'title' => $title,
            'slug' => $slug,
        ];

        return response()->json(
            [
                'exists' => false,
                'book' => $data
            ],
        );
    }

    private function createOrGetBook($bookData)
    {
        $slug = Str::of($bookData['title'])
            ->lower()
            ->replaceMatches('/[^a-z0-9\s]/', '')  // Remove non-alphanumeric characters
            ->replace(' ', '-');  // Replace spaces with hyphens

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
            $slug = preg_replace('/[^a-z0-9\s]/', '', $slug);  // Remove non-alphanumeric characters
            $slug = str_replace(' ', '-', $slug); // Replace spaces with hyphens

            $author['slug'] = $slug;

            return Author::firstOrCreate(['slug' => $slug, 'first_name' => $firstName, 'last_name' => $lastName]);
        })->all();
    }
    private function handleGenres($genresData)
    {
        return collect($genresData)->map(function ($genre) {
            return Genre::firstOrCreate(['name' => $genre['name']]);
        })->all();
    }
    private function handleVersions($versionsData, $bookData)
    {
        // For each version in versionsData, if it has a version_id that means it already exists and we can just add it to the array as-is
        // If it doesn't have a version_id, we need to create a new version record
        return collect($versionsData)->map(function ($version) use ($bookData) {
            $book_id = $bookData['book_id'];
            if (isset($version['version_id'])) {
                return Version::find($version['version_id']);
            }

            $format = Format::find($version['format']['format_id']);

            if (!$format) {
                // Handle error here
                return;
            }

            $version['format_id'] = $format->format_id;
            $version['book_id'] = $book_id;

            return Version::create($version);
        })->all();
    }

    private function handleReadInstances($readInstancesData, $bookData, $versionsData)
    {
        return collect($readInstancesData)->map(function ($readInstance) use ($bookData, $versionsData) {
            $book_id = $bookData['book_id'];
            $version_id = null;

            if (isset($readInstance['version_id'])) {
                $version_id = $readInstance['version_id'];
            } else {
                // Return the first version (FOR NOW!!!)
                $version_id = $versionsData[0]->version_id;
            }


            $readInstance['book_id'] = $book_id;
            $readInstance['version_id'] = $version_id;

            return ReadInstance::create($readInstance);
        })->all();
    }

    private function attachModels($book, $authors, $genres, $versions)
    {
        $authorIds = array_map(function ($author) {
            return $author->author_id;
        }, $authors);

        $genreIds = array_map(function ($genre) {
            return $genre->genre_id;
        }, $genres);

        $book->authors()->attach($authorIds);
        $book->genres()->attach($genreIds);
        $book->versions()->saveMany($versions);
    }

    public function completeBookCreation(Request $request)
    {
        $bookData = $request['bookData'];

        DB::beginTransaction();

        try {
            // Create the main book record
            $book = $this->createOrGetBook($bookData["book"]);
            $authors = $this->handleAuthors($bookData["authors"]);
            $genres = $this->handleGenres($bookData["genres"]);
            $versions = $this->handleVersions($bookData["versions"], $book);
            $read_instances = $this->handleReadInstances($bookData["read_instances"], $book, $versions);

            $this->attachModels($book, $authors, $genres, $versions);

            // Check if the book should be added to the backlog
            if ($bookData["addToBacklog"]) {
                // Add the book to the backlog. Determine the order as needed.
                $order = BacklogItem::max('backlog_ordinal') + 1;
                $book->addToBacklog($order);
            }

            // If all operations are successful, commit the transaction
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Book and related records created successfully.',
                'book' => $book,
                'authors' => $authors,
                'genres' => $genres,
                'versions' => $versions,
                'read_instances' => $read_instances,
            ]);
        } catch (\Exception $e) {
            // If any operation fails, roll back the transaction
            DB::rollBack();

            return response()->json([
                'success'   => false,
                'message'   => 'Error occurred, creation aborted. ' . $e->getMessage(),
                'trace'     => $e->getTrace(),
            ]);
        }
    }
}
