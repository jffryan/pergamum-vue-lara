<?php

namespace App\Http\Requests;

use App\Services\LocationService;

/**
 * `PATCH|PUT /api/locations/{location}` — rename, recode, rekind, reorder,
 * or move (a `parent_id` change is a move; the cycle guard is
 * `LocationService::update()`'s job and surfaces as a 422 with
 * `reason_code: location_cycle`).
 *
 * Everything is `sometimes`: only the keys the caller sends are applied, so
 * a rename doesn't have to restate the parent.
 */
class UpdateLocationRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        if ($this->has('code')) {
            $this->merge(['code' => LocationService::normalizeCode((string) $this->input('code', ''))]);
        }
    }

    public function rules(): array
    {
        return [
            'code' => ['sometimes', 'required', 'string', 'max:60'],
            'name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'kind' => ['sometimes', 'required', 'string', 'max:40'],
            'parent_id' => ['sometimes', 'nullable', 'integer', 'exists:locations,location_id'],
            'ordinal' => ['sometimes', 'nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * Only the keys that were actually sent, so the service can tell "set
     * name to null" apart from "didn't mention name".
     *
     * @return array<string, mixed>
     */
    public function locationAttributes(): array
    {
        return $this->validated();
    }
}
