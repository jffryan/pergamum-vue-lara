<?php

namespace App\Services;

use App\Models\Book;
use App\Models\ReadInstance;

class BookService
{
    public function getBookWithRelations($identifier, $type = 'id')
    {
        $query = Book::with('authors', 'versions', 'versions.format', 'genres', 'readInstances');

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
    
    public function getCompletedItemsForYear($year)
    {
        return Book::with(['authors', 'versions.format', 'genres', 'versions.readInstances' => function ($query) use ($year) {
            $query->whereYear('date_read', $year)
                  ->orderBy('date_read', 'asc');
        }])->whereHas('versions.readInstances', function ($query) use ($year) {
            $query->whereYear('date_read', $year);
        })->get()
        ->map(function ($book) use ($year) {
            return $this->transformCompletedBook($book, $year);
        })->sortBy(function ($item) {
            return $item['readInstances']->first()->date_read ?? null;
        })->values();
    }

    protected function transformCompletedBook($book, $year)
    {
        $bookAttributes = $book->only(['book_id', 'title', 'slug']);

        return [
            'book' => $bookAttributes,
            'authors' => $book->authors,
            'versions' => $book->versions->map(function ($version) use ($year) {
                return [
                    'version_id' => $version->version_id,
                    'page_count' => $version->page_count,
                    'audio_runtime' => $version->audio_runtime,
                    'format' => [
                        'format_id' => $version->format->format_id,
                        'name' => $version->format->name,
                        'slug' => $version->format->slug,
                    ],
                    'readInstances' => $version->readInstances->filter(function ($instance) use ($year) {
                        return $instance->date_read->year == $year;
                    })->sortBy('date_read')->values()
                ];
            }),
            'genres' => $book->genres,
            'readInstances' => $book->readInstances->filter(function ($instance) use ($year) {
                return $instance->date_read->year == $year;
            })->sortBy('date_read')->values()
        ];
    }
}
