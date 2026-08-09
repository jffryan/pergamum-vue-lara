<?php

namespace App\Http\Controllers;

use App\Http\Requests\CompleteBookCreationRequest;
use App\Http\Requests\CreateBookTitleRequest;
use App\Models\Author;
use App\Models\Book;
use App\Models\ReadInstance;
use App\Models\Version;
use App\Services\GenreService;
use App\Support\BookCreator;
use App\Support\Slugger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NewBookController extends Controller
{
    protected $genreService;

    public function __construct(GenreService $genreService)
    {
        $this->genreService = $genreService;
    }

    public function createOrGetBookByTitle(CreateBookTitleRequest $request)
    {
        $title = $request->title();

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

    /**
     * Existing copies are looked up; new ones are created against the book.
     *
     * The request has already reduced each new row to the length fields its
     * format carries, so an audiobook can't arrive here carrying a page count.
     */
    private function handleVersions(array $versions, Book $book)
    {
        $existing = Version::whereIn('version_id', $versions['existing'])->get()->all();

        $created = collect($versions['new'])->map(function ($attributes) use ($book) {
            return Version::create($attributes + ['book_id' => $book->book_id]);
        })->all();

        return array_merge($existing, $created);
    }

    private function handleReadInstances($readInstancesData, Book $book, $versions)
    {
        return collect($readInstancesData)->map(function ($readInstance) use ($book, $versions) {
            // FOR NOW: a read with no version named is filed against the first
            // copy. /feature-plans/new-book-creation.md item 11 tracks
            // threading the chosen version through the SPA instead.
            $version_id = $readInstance['version_id'] ?? ($versions[0]->version_id ?? null);

            return ReadInstance::create([
                'book_id' => $book->book_id,
                'version_id' => $version_id,
                'date_read' => $readInstance['date_read'] ?? null,
                'rating' => $readInstance['rating'] ?? null,
                'user_id' => auth()->id(),
            ]);
        })->all();
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

    /**
     * Create a book and everything hanging off it, or nothing.
     *
     * A malformed payload is now a 422 from `CompleteBookCreationRequest`
     * before this runs. What's left in the catch is genuinely unexpected, so
     * it answers 500 rather than the 200-with-`success:false` this used to
     * return — a failure the SPA had to inspect the body to notice.
     */
    public function completeBookCreation(CompleteBookCreationRequest $request)
    {
        DB::beginTransaction();

        try {
            // Create the main book record
            $book = BookCreator::create($request->title());
            $authors = $this->handleAuthors($request->authors());
            $versions = $this->handleVersions($request->versions(), $book);
            $read_instances = $this->handleReadInstances($request->readInstances(), $book, $versions);

            $this->attachModels($book, $authors, $versions);

            $genres = $this->genreService->attachByName($book, $request->genreNames());

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
            Log::error('Error creating book: '.$e->getMessage());

            return response()->json([
                'success' => false,
                'message' => 'Error occurred, creation aborted.',
            ], 500);
        }
    }
}
