<?php

namespace App\Services;

use App\Models\Book;
use App\Models\ReadInstance;

class BookService
{
    public function getBookWithRelations($identifier, $type = 'id')
    {
        // `readInstances` needs no user predicate — BelongsToCurrentUser is on
        // the model, so the eager load is already the requesting user's.
        $query = Book::with(['authors', 'versions', 'versions.format', 'genres', 'readInstances']);

        if ($type === 'slug') {
            $book = $query->where('slug', $identifier)->firstOrFail();
        } else {
            $book = $query->where('book_id', $identifier)->firstOrFail();
        }

        $bookAttributes = $book->only(['book_id', 'title', 'slug']);

        $authorIds = $book->authors->pluck('author_id');
        $authorRelatedBooks = Book::with(['authors', 'genres'])
            ->whereHas('authors', function ($q) use ($authorIds) {
                $q->whereIn('authors.author_id', $authorIds);
            })
            ->where('book_id', '!=', $book->book_id)
            ->limit(3)
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
