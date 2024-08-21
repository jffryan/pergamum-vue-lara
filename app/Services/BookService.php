<?php

namespace App\Services;

use App\Models\Book;
use App\Models\ReadInstance;

class BookService
{
    public function getBookWithRelations($identifier, $type = 'id')
    {
        $query = Book::with("authors", "versions", "versions.format", "genres", "readInstances");

        if ($type === 'slug') {
            $book = $query->where("slug", $identifier)->firstOrFail();
        } else {
            $book = $query->where("book_id", $identifier)->firstOrFail();
        }

        $bookAttributes = $book->only(['book_id', 'title', 'slug', 'is_completed', 'rating']);

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
            $bookAttributes = $book->only(['book_id', 'title', 'slug', 'is_completed', 'rating']);
            return [
                'book' => $bookAttributes,
                'authors' => $book->authors,
                'versions' => $book->versions,
                'genres' => $book->genres,
                'readInstances' => $book->readInstances,
            ];
        });
    }

    public function getBooksByYear($year)
    {
        // Validate that the year is a valid number
        if (!is_numeric($year) || strlen($year) != 4) {
            return response()->json(['error' => 'Invalid year format'], 400);
        }

        $readInstances = ReadInstance::whereYear('date_read', '=', $year)
            ->with('book.authors', 'book.genres', 'book.versions', 'book.versions.format')
            ->orderBy('date_read', 'asc')
            ->get();

        $books = $readInstances->map(function ($instance) {
            $book = $instance->book;
            $bookAttributes = $book->only(['book_id', 'title', 'slug', 'is_completed', 'rating']);

            return [
                'book' => $bookAttributes,
                'authors' => $book->authors,
                'genres' => $book->genres,
                'versions' => $book->versions->map(function ($version) {
                    return [
                        'version_id' => $version->version_id,
                        'page_count' => $version->page_count,
                        'audio_runtime' => $version->audio_runtime,
                        'format_id' => $version->format_id,
                        'book_id' => $version->book_id,
                        'created_at' => $version->created_at,
                        'updated_at' => $version->updated_at,
                        'nickname' => $version->nickname,
                        'format' => [
                            'format_id' => $version->format->format_id,
                            'name' => $version->format->name,
                            'slug' => $version->format->slug,
                            'created_at' => $version->format->created_at,
                            'updated_at' => $version->format->updated_at,
                        ]
                    ];
                }),
                'readInstances' => [
                    [
                        'read_instances_id' => $instance->read_instances_id,
                        'book_id' => $instance->book_id,
                        'version_id' => $instance->version_id,
                        'date_read' => $instance->date_read->format('Y-m-d'),
                        'created_at' => $instance->created_at->format('Y-m-d'),
                        'updated_at' => $instance->updated_at->format('Y-m-d')
                    ]
                ]
            ];
        });

        return $books;
    }
}
