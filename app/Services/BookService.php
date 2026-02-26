<?php

namespace App\Services;

use App\Models\Book;
use App\Models\ReadInstance;

class BookService
{
    public function getBookWithRelations($identifier, $type = 'id')
    {
        $userId = auth()->id();
        $query = Book::with(['authors', 'versions', 'versions.format', 'genres', 'readInstances' => function ($q) use ($userId) {
            $q->where('user_id', $userId);
        }]);

        if ($type === 'slug') {
            $book = $query->where('slug', $identifier)->firstOrFail();
        } else {
            $book = $query->where('book_id', $identifier)->firstOrFail();
        }

        $bookAttributes = $book->only(['book_id', 'title', 'slug']);

        return [
            'book' => $bookAttributes,
            'authors' => $book->authors,
            'versions' => $book->versions,
            'genres' => $book->genres,
            'readInstances' => $book->readInstances,
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
            ->where('user_id', auth()->id())
            ->distinct()
            ->orderBy('year', 'desc')
            ->pluck('year')
            ->map(fn ($year) => (int) $year)
            ->toArray();
    }

    public function getCompletedItemsForYear($year)
    {
        $userId = auth()->id();
        return Book::with(['authors', 'versions.format', 'genres',
            'versions.readInstances' => function ($query) use ($year, $userId) {
                $query->where('user_id', $userId)
                      ->whereYear('date_read', $year)
                      ->orderBy('date_read', 'asc');
            },
            'readInstances' => function ($query) use ($year, $userId) {
                $query->where('user_id', $userId)->whereYear('date_read', $year);
            },
        ])->whereHas('versions.readInstances', function ($query) use ($year, $userId) {
            $query->where('user_id', $userId)->whereYear('date_read', $year);
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
