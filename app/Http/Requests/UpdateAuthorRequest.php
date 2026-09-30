<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesAuthorNames;

/**
 * `PATCH|PUT /api/authors/{author}` — rename. The shape rule is the one every
 * book door uses (a first name or a last name, not necessarily both); whether
 * the new name belongs to someone else is `AuthorService::rename()`'s call,
 * and surfaces as a 409 carrying that author rather than as a 422.
 */
class UpdateAuthorRequest extends ApiFormRequest
{
    use ValidatesAuthorNames;

    protected function prepareForValidation(): void
    {
        $this->merge($this->normalizeAuthorNamesIn([
            $this->only(['first_name', 'last_name']),
        ])[0]);
    }

    public function rules(): array
    {
        return $this->singleAuthorNameRules();
    }

    public function messages(): array
    {
        return $this->singleAuthorNameMessages();
    }

    protected function reasonCodes(): array
    {
        return $this->singleAuthorNameReasonCodes();
    }

    /**
     * @return array{first_name: ?string, last_name: ?string}
     */
    public function names(): array
    {
        $validated = $this->validated();

        return [
            'first_name' => $validated['first_name'] ?? null,
            'last_name' => $validated['last_name'] ?? null,
        ];
    }
}
