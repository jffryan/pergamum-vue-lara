<?php

namespace App\Http\Requests;

use App\Services\GenreService;

/**
 * `PATCH|PUT /api/genres/{genre}` — rename. Same shape rules as
 * {@see StoreGenreRequest}; renaming a genre to its own current name is a
 * no-op success, not a conflict (`GenreService::findConflict()` excludes it).
 */
class UpdateGenreRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['name' => GenreService::normalize((string) $this->input('name', ''))]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }

    public function name(): string
    {
        return $this->validated()['name'];
    }
}
