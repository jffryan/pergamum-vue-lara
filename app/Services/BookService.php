<?php

namespace App\Services;

use App\Models\Book;
use Illuminate\Support\Arr;

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
}
