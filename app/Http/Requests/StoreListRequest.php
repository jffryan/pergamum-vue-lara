<?php

namespace App\Http\Requests;

/**
 * `POST /api/lists`. Authorization is the resource policy's job — see
 * `ListController::__construct()`.
 */
class StoreListRequest extends ApiFormRequest
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
