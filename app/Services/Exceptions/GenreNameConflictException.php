<?php

namespace App\Services\Exceptions;

use App\Models\Genre;
use App\Services\GenreService;
use RuntimeException;

/**
 * Raised by {@see GenreService} when a create or rename would
 * produce a second row sharing a name.
 *
 * Deliberately not a `Rule::unique` validation failure. A 422 from
 * `ApiFormRequest` carries only field/message pairs, and the SPA needs the
 * colliding genre's `genre_id` so it can offer "merge into it instead"
 * without a second round-trip. The controller renders this as a 409 with the
 * conflicting genre in the body.
 */
class GenreNameConflictException extends RuntimeException
{
    public function __construct(
        public readonly Genre $conflict,
        public readonly string $reasonCode = 'genre_name_taken',
    ) {
        parent::__construct("A genre named \"{$conflict->name}\" already exists.");
    }
}
