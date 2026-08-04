<?php

namespace App\Http\Requests;

/**
 * `PUT|PATCH /api/lists/{list}` — rename only; a list has no other editable
 * metadata yet.
 */
class UpdateListRequest extends ApiFormRequest
{
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
