<?php

namespace App\Http\Requests;

use Illuminate\Validation\Rule;

/**
 * `POST /api/authors/{author}/merge` — `{author}` is the winner, `source_ids`
 * the losers. N→1, the same contract as {@see MergeGenresRequest}.
 */
class MergeAuthorsRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'source_ids' => ['required', 'array', 'min:1'],
            'source_ids.*' => [
                'integer',
                'exists:authors,author_id',
                // Merging an author into itself would delete the winner.
                Rule::notIn([$this->route('author')?->author_id]),
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
