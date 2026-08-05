<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * `POST /api/genres/{genre}/merge` — `{genre}` is the winner, `source_ids` the
 * losers. N→1 in one request, so cleaning up four strays isn't four round-trips.
 */
class MergeGenresRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'source_ids' => ['required', 'array', 'min:1'],
            'source_ids.*' => [
                'integer',
                'exists:genres,genre_id',
                // Merging a genre into itself would delete the winner.
                Rule::notIn([$this->route('genre')?->genre_id]),
            ],
        ];
    }

    /**
     * @return array<int>
     */
    public function sourceIds(): array
    {
        return array_map('intval', $this->validated()['source_ids']);
    }
}
