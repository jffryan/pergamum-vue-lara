<?php 

namespace App\Services;

use App\Models\Author;

class AuthorService
{
    public function getAuthorWithRelations($identifier, $type = 'slug')
    {
        $query = Author::with('books.authors', 'books.genres', 'books.readInstances', 'books.versions', 'books.versions.format');

        if ($type === 'id') {
            $author = $query->where('author_id', $identifier)->firstOrFail();
        } else {
            $author = $query->where('slug', $identifier)->firstOrFail();
        }

        $authorAttributes = $author->only(['author_id', 'first_name', 'last_name', 'slug', 'bio']);

        return [
            'author' => $authorAttributes,
            'books' => $author->books->map(function ($book) {
                return [
                    'book' => $book->only(['book_id', 'title', 'slug']),
                    'authors' => $book->authors,
                    'genres' => $book->genres,
                    'versions' => $book->versions,
                    'read_instances' => $book->readInstances,
                ];
            }),
        ];
    }
}