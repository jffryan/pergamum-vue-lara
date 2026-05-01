<?php

namespace App\Http\Controllers;

use App\Models\Author;
use App\Models\Book;
use App\Models\Format;
use App\Models\Genre;
use App\Models\ReadInstance;
use App\Models\Version;
use App\Support\BookCreator;
use App\Support\RatingValidator;
use App\Support\Slugger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class NewBookController extends Controller
{
    //
    public function createOrGetBookByTitle(Request $request)
    {
        $title = $request['title'];

        $slug = Slugger::for($title);

        // Look for an existing book by the slug
        $userId = auth()->id();
        $existingBook = Book::with(['authors', 'genres', 'versions', 'versions.format', 'versions.readInstances' => function ($query) use ($userId) {
            $query->where('user_id', $userId);
        }])->where('slug', $slug)->first();

        if ($existingBook) {
            return response()->json(
                [
                    'exists' => true,
                    'book' => $existingBook,
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
                'book' => $data,
            ],
        );
    }

    private function handleAuthors($authorsData)
    {
        return collect($authorsData)->map(function ($author) {
            $firstName = $author['first_name'] ?? '';
            $lastName = $author['last_name'] ?? '';
            $slug = Slugger::for(trim("$firstName $lastName"));

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

            if (! $format) {
                throw new \Exception("Format not found for version (format_id: {$version['format']['format_id']}).");
            }

            $version['format_id'] = $format->format_id;
            $version['book_id'] = $book_id;

            return Version::create($version);
        })->all();
    }

    private function handleReadInstances($readInstancesData, $bookData, $versionsData)
    {
        return collect($readInstancesData)->map(function ($readInstance) use ($bookData, $versionsData) {
            if (array_key_exists('rating', $readInstance) && $readInstance['rating'] !== null && $readInstance['rating'] !== '') {
                if (! RatingValidator::isValid($readInstance['rating'])) {
                    throw new \Exception("rating '{$readInstance['rating']}' must be between 0.5 and 5 in 0.5 steps");
                }
            }

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
            $readInstance['user_id'] = auth()->id();

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
            $book = BookCreator::create($bookData['book']['title']);
            $authors = $this->handleAuthors($bookData['authors']);
            $genres = $this->handleGenres($bookData['genres']);
            $versions = $this->handleVersions($bookData['versions'], $book);
            $read_instances = $this->handleReadInstances($bookData['read_instances'], $book, $versions);

            $this->attachModels($book, $authors, $genres, $versions);

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
                'success' => false,
                'message' => 'Error occurred, creation aborted. '.$e->getMessage(),
            ]);
        }
    }
}
