<?php

namespace App\Services;

use App\Models\Book;
use App\Models\ReadInstance;

class BookService
{
    /**
     * How many "more by this author" books the detail payload carries. The
     * page renders them as library rows rather than cards, so a handful reads
     * as a shelf without pushing the book's own history off the screen.
     */
    private const RELATED_BOOK_LIMIT = 6;

    public function getBookWithRelations($identifier, $type = 'id')
    {
        // `readInstances` needs no user predicate — BelongsToCurrentUser is on
        // the model, so the eager load is already the requesting user's.
        // `versions.location` feeds the book page's "where is it" line — one
        // small row per shelved copy, null for unshelved.
        //
        // Ordered for the same reason `BookListing::query()` orders its own:
        // the detail page reads `authors[0]` as the primary author and
        // `readInstances[0]` as the latest read, so the order has to be a
        // promise rather than whatever the pivot happened to return. Copies
        // go oldest-first, matching the library's `versions[0]`.
        $query = Book::with([
            'authors' => fn ($q) => $q->orderBy('book_author.author_ordinal'),
            'versions' => fn ($q) => $q->orderBy('versions.version_id'),
            'versions.format',
            'versions.location',
            'genres' => fn ($q) => $q->orderBy('genres.name'),
            'readInstances' => fn ($q) => $q->orderByDesc('date_read'),
        ]);

        if ($type === 'slug') {
            $book = $query->where('slug', $identifier)->firstOrFail();
        } else {
            $book = $query->where('book_id', $identifier)->firstOrFail();
        }

        $bookAttributes = $book->only(['book_id', 'title', 'slug']);

        $authorIds = $book->authors->pluck('author_id');

        // `whereHas` keeps this one row per book however many authors it
        // shares, and the title order makes the set stable — an unordered
        // `limit(6)` returned a different three books on consecutive requests,
        // so the "more by this author" block flickered on every reload.
        $authorRelatedBooks = Book::with([
            'authors' => fn ($q) => $q->orderBy('book_author.author_ordinal'),
            'genres' => fn ($q) => $q->orderBy('genres.name'),
        ])
            ->whereHas('authors', function ($q) use ($authorIds) {
                $q->whereIn('authors.author_id', $authorIds);
            })
            ->where('book_id', '!=', $book->book_id)
            ->orderBy('books.title')
            ->limit(self::RELATED_BOOK_LIMIT)
            ->get()
            ->map(fn ($b) => [
                'book' => $b->only(['book_id', 'title', 'slug']),
                'authors' => $b->authors,
                'genres' => $b->genres,
            ]);

        return [
            'book' => $bookAttributes,
            'authors' => $book->authors,
            'versions' => $book->versions,
            'genres' => $book->genres,
            'readInstances' => $book->readInstances,
            'authorRelatedBooks' => $authorRelatedBooks,
        ];
    }

    public function getBooksList($books)
    {
        return $books->map(function ($book) {
            $bookAttributes = $book->only(['book_id', 'title', 'slug']);

            return [
                'book' => $bookAttributes,
                'authors' => $book->authors,
                'versions' => $book->versions,
                'genres' => $book->genres,
                'readInstances' => $book->readInstances,
            ];
        });
    }

    public function getAvailableYears(): array
    {
        return ReadInstance::selectRaw('YEAR(date_read) as year')
            ->whereNotNull('date_read')
            ->distinct()
            ->orderBy('year', 'desc')
            ->pluck('year')
            ->map(fn ($year) => (int) $year)
            ->toArray();
    }

    public function getCompletedItemsForYear($year)
    {
        return Book::with(['authors', 'versions.format', 'genres',
            'versions.readInstances' => function ($query) use ($year) {
                $query->whereYear('date_read', $year)
                    ->orderBy('date_read', 'asc');
            },
            'readInstances' => function ($query) use ($year) {
                $query->whereYear('date_read', $year);
            },
        ])->whereHas('versions.readInstances', function ($query) use ($year) {
            $query->whereYear('date_read', $year);
        })->get()
            ->map(fn ($book) => $this->transformCompletedBook($book))
            ->sortBy(fn ($item) => $item['readInstances']->first()->date_read ?? null)
            ->values();
    }

    protected function transformCompletedBook($book)
    {
        $bookAttributes = $book->only(['book_id', 'title', 'slug']);

        return [
            'book' => $bookAttributes,
            'authors' => $book->authors,
            'versions' => $book->versions->map(function ($version) {
                return [
                    'version_id' => $version->version_id,
                    'page_count' => $version->page_count,
                    'audio_runtime' => $version->audio_runtime,
                    'is_discarded' => $version->is_discarded,
                    'discarded_at' => $version->discarded_at?->format('Y-m-d'),
                    'format' => [
                        'format_id' => $version->format->format_id,
                        'name' => $version->format->name,
                        'slug' => $version->format->slug,
                    ],
                    'readInstances' => $version->readInstances->sortBy('date_read')->values(),
                ];
            }),
            'genres' => $book->genres,
            'readInstances' => $book->readInstances->sortBy('date_read')->values(),
        ];
    }
}
