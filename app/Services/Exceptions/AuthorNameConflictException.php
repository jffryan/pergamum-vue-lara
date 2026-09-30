<?php

namespace App\Services\Exceptions;

use App\Models\Author;
use App\Services\AuthorService;
use RuntimeException;

/**
 * Raised by {@see AuthorService::rename()} when the new name slugs to a
 * different author's row.
 *
 * Two authors can't share a slug — `authors.slug` is unique, and every ingest
 * door finds authors by it — so a rename onto a taken slug is really a
 * statement that the two rows are the same person. The controllers render
 * this as a 409 carrying the other author, so the SPA can offer the merge
 * without going back to the server for its id. Same shape as
 * {@see GenreNameConflictException}.
 */
class AuthorNameConflictException extends RuntimeException
{
    public function __construct(
        public readonly Author $conflict,
        public readonly string $reasonCode = 'author_name_taken',
    ) {
        $name = trim($conflict->first_name.' '.$conflict->last_name);

        parent::__construct("An author named \"{$name}\" already exists.");
    }
}
