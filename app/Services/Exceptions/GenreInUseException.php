<?php

namespace App\Services\Exceptions;

use App\Services\GenreService;
use RuntimeException;

/**
 * Raised by {@see GenreService::delete()} when a genre still has
 * books attached and the caller did not pass `force`.
 *
 * Deleting a genre off forty books is a legitimate operation — it's how you
 * remove a junk tag without a merge-into-nothing workaround — so this is a
 * speed bump, not a refusal. It exists so a mis-wired button or a stray
 * request can't do it silently; the book count travels along so the SPA can
 * state the impact before asking for confirmation.
 */
class GenreInUseException extends RuntimeException
{
    public function __construct(
        public readonly int $booksCount,
        public readonly string $reasonCode = 'genre_in_use',
    ) {
        parent::__construct("This genre is attached to {$booksCount} book(s).");
    }
}
