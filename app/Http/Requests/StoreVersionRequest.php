<?php

namespace App\Http\Requests;

use App\Models\Format;

/**
 * `POST /api/versions` — adding a copy to a book that already exists.
 *
 * Same rules as the version rows inside the book create/edit payloads, but a
 * different envelope: this one carries `book_id` (the book is not in the URL)
 * and nests the format as an object rather than a bare id, because the SPA
 * hands the whole selected format through.
 */
class StoreVersionRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'version' => ['required', 'array'],
            'version.book_id' => ['required', 'integer', 'exists:books,book_id'],
            'version.format.format_id' => ['required', 'integer', 'exists:formats,format_id'],
            'version.nickname' => ['nullable', 'string', 'max:255'],
            'version.page_count' => ['nullable', 'integer', 'min:0'],
            'version.audio_runtime' => ['nullable', 'integer', 'min:0'],
            // Same rule as `MoveVersionRequest` and the new-book door: any
            // real location row, or null for unshelved.
            'version.location_id' => ['nullable', 'integer', 'exists:locations,location_id'],
        ];
    }

    /**
     * The shelf to place the new copy on, if any. Kept out of
     * {@see versionAttributes()} on purpose: placement goes through
     * `LocationService::shelveVersion`, not a mass-assigned column.
     */
    public function locationId(): ?int
    {
        $value = $this->validated()['version']['location_id'] ?? null;

        return $value === null ? null : (int) $value;
    }

    /**
     * Flattened for `Version::create()`, with the length fields reduced to
     * the ones the chosen format carries.
     *
     * This endpoint used to pass `page_count` and `audio_runtime` straight
     * through, so it was the one write path that could still file a runtime
     * against a paperback — the create and edit paths have gone through
     * `Format::lengthFieldsFrom()` since capability flags landed.
     */
    public function versionAttributes(): array
    {
        $version = $this->validated()['version'];
        $format = Format::findOrFail($version['format']['format_id']);

        return [
            'book_id' => $version['book_id'],
            'format_id' => $format->format_id,
            'nickname' => $version['nickname'] ?? null,
        ] + $format->lengthFieldsFrom($version);
    }
}
