<?php

namespace App\Http\Requests;

use App\Services\GenreService;

/**
 * `POST /api/genres`. Shape only — uniqueness is `GenreService`'s job and
 * surfaces as a 409 carrying the colliding genre, not as a `Rule::unique` 422.
 * Authorization is the resource policy's job — see `GenreController::__construct()`.
 */
class StoreGenreRequest extends ApiFormRequest
{
    /**
     * Normalizing before validation is what makes `"   "` a `required` failure
     * rather than a genre named four spaces.
     */
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
