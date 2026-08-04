<?php

namespace App\Http\Requests;

/**
 * `POST /api/create-book/title` — step one of the multi-step create flow,
 * which answers "does a book with this title already exist?".
 *
 * Read-only despite the verb; it writes nothing.
 */
class CreateBookTitleRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
        ];
    }

    public function title(): string
    {
        return $this->validated()['title'];
    }
}
