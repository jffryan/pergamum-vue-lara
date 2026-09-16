<?php

namespace App\Http\Controllers;

use App\Http\Requests\CompleteBookCreationRequest;
use App\Http\Requests\CreateBookTitleRequest;
use App\Models\Book;
use App\Models\ReadInstance;
use App\Models\Version;
use App\Services\AuthorService;
use App\Services\GenreService;
use App\Support\BookCreator;
use App\Support\BookMatcher;
use App\Support\Slugger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class NewBookController extends Controller
{
    protected $genreService;

    protected $authorService;

    public function __construct(GenreService $genreService, AuthorService $authorService)
    {
        $this->genreService = $genreService;
        $this->authorService = $authorService;
    }

    /**
     * The SPA's first step: does a book with this title exist already?
     *
     * The title is all the user has typed at this point, so this can't apply
     * `BookMatcher::find` — it returns every same-title book with its authors
     * and lets the user decide which one, if any, they mean. `book` is the
     * stub the SPA round-trips; its slug is provisional, since the real one is
     * settled by `BookCreator` on submit.
     */
    public function createOrGetBookByTitle(CreateBookTitleRequest $request)
    {
        $title = $request->title();

        $matches = BookMatcher::sameTitle($title)->map(fn (Book $book) => [
            'book_id' => $book->book_id,
            'title' => $book->title,
            'slug' => $book->slug,
            'authors' => $book->authors,
        ])->values();

        return response()->json([
            'exists' => $matches->isNotEmpty(),
            'book' => [
                'title' => $title,
                'slug' => Slugger::for($title),
            ],
            'matches' => $matches,
        ]);
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
            // Authors go to the creator too: a second book with a taken title
            // is filed under the primary author's surname, not `-1`.
            $book = BookCreator::create($request->title(), $request->authors());
            $authors = $this->authorService->attachToBook($book, $request->authors());
            $versions = $this->handleVersions($request->versions(), $book);
            $read_instances = $this->handleReadInstances($request->readInstances(), $book, $versions);

            $book->versions()->saveMany($versions);

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
