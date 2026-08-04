<?php

namespace App\Http\Requests;

use App\Models\BookList;
use Illuminate\Contracts\Validation\Validator;

/**
 * `PATCH /api/lists/{list}/reorder`.
 *
 * Reorder takes the list's item ids in their new order — the whole list,
 * every time. A partial payload is rejected rather than interpreted, because
 * "these three first, leave the rest" has no unambiguous meaning for the
 * items not named.
 *
 * The membership check used to live in the controller and returned a bare
 * message; here it is validation, so the offending ids come back with it.
 */
class ReorderListRequest extends ApiFormRequest
{
    /**
     * Unlike the rest of this namespace, this request authorizes itself.
     * Validation below reads the list's own item ids, so it must not run for
     * someone who doesn't own the list — otherwise a foreign list answers
     * "which ids are yours" with a 422 instead of a 403. FormRequest runs
     * `authorize()` first, which is exactly the ordering needed.
     */
    public function authorize(): bool
    {
        return $this->user()?->can('update', $this->list()) ?? false;
    }

    public function rules(): array
    {
        return [
            'items' => ['required', 'array'],
            // `distinct` matters: the old count-based membership check passed
            // a payload like [A, A, B] against a list [A, B, C], then wrote
            // A's ordinal twice and left C's untouched. The unique index on
            // (list_id, ordinal) was dropped in 2026_02_22, so nothing below
            // this catches a collision.
            'items.*' => ['integer', 'distinct'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->has('items') || $validator->errors()->hasAny(['items.*'])) {
                return;
            }

            $submitted = array_map('intval', $this->input('items', []));
            $actual = $this->list()->items()->pluck('list_item_id')->map('intval')->all();

            $unexpected = array_values(array_diff($submitted, $actual));
            $missing = array_values(array_diff($actual, $submitted));

            if ($unexpected === [] && $missing === []) {
                return;
            }

            // Kept verbatim: callers key on this string. The detail below it
            // is additive.
            $validator->errors()->add('items', 'Invalid item IDs for this list.');

            if ($unexpected !== []) {
                $validator->errors()->add('items', 'Not items of this list: '.implode(', ', $unexpected).'.');
            }

            if ($missing !== []) {
                $validator->errors()->add('items', 'Missing from the payload: '.implode(', ', $missing).'.');
            }
        });
    }

    /**
     * The list from the route. Bound by the router, already authorized by
     * `ListController::reorder()` before the ordinals are written.
     */
    public function list(): BookList
    {
        return $this->route('list');
    }

    /** @return array<int, int> */
    public function orderedItemIds(): array
    {
        return array_map('intval', $this->validated()['items']);
    }
}
