<?php

namespace App\Http\Requests;

use App\Services\LocationService;

/**
 * `POST /api/locations`. Shape only — code uniqueness is `LocationService`'s
 * job and surfaces as a 409 carrying the colliding location, not as a
 * `Rule::unique` 422 (same split as genres).
 *
 * `kind` is an open vocabulary by design ('room', 'bookcase', 'shelf' today;
 * a 'box' tomorrow costs nothing), so it is validated as a string, not an
 * enum.
 */
class StoreLocationRequest extends ApiFormRequest
{
    protected function prepareForValidation(): void
    {
        $this->merge(['code' => LocationService::normalizeCode((string) $this->input('code', ''))]);
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:60'],
            'name' => ['nullable', 'string', 'max:255'],
            'kind' => ['required', 'string', 'max:40'],
            'parent_id' => ['nullable', 'integer', 'exists:locations,location_id'],
            'ordinal' => ['nullable', 'integer', 'min:0'],
        ];
    }

    /**
     * @return array{code: string, name: ?string, kind: string, parent_id: ?int, ordinal: ?int}
     */
    public function locationAttributes(): array
    {
        $validated = $this->validated();

        return [
            'code' => $validated['code'],
            'name' => $validated['name'] ?? null,
            'kind' => $validated['kind'],
            'parent_id' => $validated['parent_id'] ?? null,
            'ordinal' => $validated['ordinal'] ?? null,
        ];
    }
}
