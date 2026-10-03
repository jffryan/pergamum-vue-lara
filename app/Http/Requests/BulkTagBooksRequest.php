<?php

namespace App\Http\Requests;

use App\Services\GenreService;

/**
 * `POST /api/books/bulk-tag` — attach every genre in `genre_ids` and `names`
 * to every book in `book_ids`, additively. At least one of the two genre keys.
 *
 * `genre_ids` is for callers that already hold the row (the genre page);
 * `names` is for typed tags, and may create the genre.
 *
 * Unlike the form doors, a blank name is a 422 rather than a dropped row: this
 * endpoint exists only to tag, so a request that names no genre has asked for
 * nothing. That matches the admin door (`StoreGenreRequest`), not ingest.
 */
class BulkTagBooksRequest extends ApiFormRequest
{
    /**
     * Normalizing before validation is what makes `"   "` a `required` failure.
     * Non-strings pass through untouched for `string` to reject.
     */
    protected function prepareForValidation(): void
    {
        $names = $this->input('names');

        if (is_array($names)) {
            $this->merge(['names' => array_map(
                fn ($name) => is_string($name) ? GenreService::normalize($name) : $name,
                $names,
            )]);
        }
    }

    public function rules(): array
    {
        return [
            // Capped so one request can't fan out into an unbounded loop of
            // pivot writes. A page of the library is 20; a list is rarely 100.
            'book_ids' => ['required', 'array', 'min:1', 'max:500'],
            'book_ids.*' => ['integer', 'exists:books,book_id'],
            'genre_ids' => ['required_without:names', 'array', 'min:1'],
            'genre_ids.*' => ['integer', 'exists:genres,genre_id'],
            'names' => ['required_without:genre_ids', 'array', 'min:1'],
            'names.*' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * Deduped: a caller mapping versions to books (a list holding two copies
     * of one novel) shouldn't have to.
     *
     * @return array<int>
     */
    public function bookIds(): array
    {
        return array_values(array_unique(array_map('intval', $this->validated()['book_ids'])));
    }

    /**
     * @return array<int>
     */
    public function genreIds(): array
    {
        return array_map('intval', $this->validated()['genre_ids'] ?? []);
    }

    /**
     * @return array<int, string>
     */
    public function names(): array
    {
        return $this->validated()['names'] ?? [];
    }
}
