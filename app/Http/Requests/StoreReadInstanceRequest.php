<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesReadDates;
use App\Rules\Rating;
use App\Rules\VersionBelongsToBook;

/**
 * `POST /api/add-read-instance`.
 *
 * The payload is nested under `readInstance` because that is what the SPA
 * sends; the nesting isn't load-bearing and can be flattened whenever the
 * caller is.
 */
class StoreReadInstanceRequest extends ApiFormRequest
{
    use NormalizesReadDates;

    public function rules(): array
    {
        return [
            'readInstance' => ['required', 'array'],
            'readInstance.book_id' => ['required', 'integer', 'exists:books,book_id'],
            'readInstance.version_id' => [
                'required',
                'integer',
                'exists:versions,version_id',
                new VersionBelongsToBook($this->input('readInstance.book_id')),
            ],
            'readInstance.date_read' => ['nullable', 'date'],
            'readInstance.rating' => ['nullable', new Rating],
        ];
    }

    protected function reasonCodes(): array
    {
        return [
            'readInstance.version_id.version_belongs_to_book' => 'version_book_mismatch',
            'readInstance.rating.rating' => 'rating_out_of_range',
        ];
    }

    protected function prepareForValidation(): void
    {
        $instance = $this->input('readInstance');

        if (! is_array($instance) || ! array_key_exists('date_read', $instance)) {
            return;
        }

        $instance['date_read'] = $this->normalizeReadDate($instance['date_read']);

        $this->merge(['readInstance' => $instance]);
    }

    /**
     * The validated read instance, ready to hand to the model. `user_id` is
     * stamped by the controller from the session and is deliberately not
     * accepted from the request.
     */
    public function readInstance(): array
    {
        return $this->validated()['readInstance'];
    }
}
