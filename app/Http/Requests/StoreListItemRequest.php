<?php

namespace App\Http\Requests;

/**
 * `POST /api/lists/{list}/items`.
 *
 * Lists hold versions, not books — a list entry names a specific copy.
 */
class StoreListItemRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'version_id' => ['required', 'integer', 'exists:versions,version_id'],
        ];
    }

    public function versionId(): int
    {
        return (int) $this->validated()['version_id'];
    }
}
