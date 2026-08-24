<?php

namespace App\Http\Requests;

/**
 * `PATCH /api/versions/{version}/location` — shelve, reshelve, or unshelve
 * one copy.
 *
 * `location_id` must be present so "unshelve" is an explicit `null`, never
 * an accidental omission. `shelf_ordinal` is optional; it is cleared
 * whenever the copy is unshelved (`LocationService::shelveVersion()`).
 */
class MoveVersionRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'location_id' => ['present', 'nullable', 'integer', 'exists:locations,location_id'],
            'shelf_ordinal' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }

    public function locationId(): ?int
    {
        $value = $this->validated()['location_id'];

        return $value === null ? null : (int) $value;
    }

    public function shelfOrdinal(): ?int
    {
        $value = $this->validated()['shelf_ordinal'] ?? null;

        return $value === null ? null : (int) $value;
    }
}
