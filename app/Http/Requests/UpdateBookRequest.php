<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesReadDates;
use App\Http\Requests\Concerns\ValidatesAuthorNames;
use App\Rules\Rating;

/**
 * `PUT|PATCH /api/books/{book}`.
 *
 * **The payload is flat.** It used to arrive wrapped as
 * `{ request: { formData: { … } } }` — two levels of envelope that existed
 * only because the SPA happened to keep the form state under that name.
 * `/feature-plans/books.md` item 2 called for flattening it alongside this
 * request class, so `book`, `authors`, `genres`, `versions` and
 * `readInstances` are now top-level keys.
 */
class UpdateBookRequest extends ApiFormRequest
{
    use NormalizesReadDates;
    use ValidatesAuthorNames;

    public function rules(): array
    {
        return $this->authorNameRules('authors') + [
            'book' => ['required', 'array'],
            'book.title' => ['required', 'string', 'max:255'],

            'authors.*.author_id' => ['nullable', 'integer', 'exists:authors,author_id'],

            'genres' => ['sometimes', 'nullable', 'array'],
            'genres.*.genre_id' => ['nullable', 'integer'],
            'genres.*.name' => ['nullable', 'string', 'max:255'],

            'versions' => ['sometimes', 'nullable', 'array'],
            'versions.*.version_id' => ['nullable', 'integer', 'exists:versions,version_id'],
            // An unknown format used to `continue` past the version, so an
            // edit that mangled the id reported success and changed nothing.
            'versions.*.format' => ['required', 'integer', 'exists:formats,format_id'],
            'versions.*.nickname' => ['nullable', 'string', 'max:255'],
            'versions.*.page_count' => ['nullable', 'integer', 'min:0'],
            'versions.*.audio_runtime' => ['nullable', 'integer', 'min:0'],

            'readInstances' => ['sometimes', 'nullable', 'array'],
            'readInstances.*.read_instance_id' => ['nullable', 'integer'],
            'readInstances.*.date_read' => ['nullable', 'date'],
            'readInstances.*.rating' => ['nullable', new Rating],
        ];
    }

    public function messages(): array
    {
        return $this->authorNameMessages('authors');
    }

    protected function reasonCodes(): array
    {
        return ['readInstances.*.rating.rating' => 'rating_out_of_range']
            + $this->authorNameReasonCodes('authors');
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('authors')) {
            $this->merge([
                'authors' => $this->normalizeAuthorNamesIn($this->input('authors')),
            ]);
        }

        if ($this->has('readInstances')) {
            $this->merge([
                'readInstances' => $this->normalizeReadDatesIn($this->input('readInstances')),
            ]);
        }
    }

    public function title(): string
    {
        return $this->validated()['book']['title'];
    }

    /** @return array<int, array<string, mixed>> */
    public function authors(): array
    {
        return $this->validated()['authors'] ?? [];
    }

    /** @return array<int, array<string, mixed>> */
    public function genres(): array
    {
        return $this->validated()['genres'] ?? [];
    }

    /** @return array<int, array<string, mixed>> */
    public function versions(): array
    {
        return $this->validated()['versions'] ?? [];
    }

    /**
     * Only reads the form already knows about. There is no UI for adding a
     * read from the edit view — that goes through `POST /add-read-instance` —
     * so a row with no `read_instance_id` is a blank the form rendered, not
     * an instruction to create one.
     *
     * @return array<int, array<string, mixed>>
     */
    public function existingReadInstances(): array
    {
        return array_values(array_filter(
            $this->validated()['readInstances'] ?? [],
            fn ($instance) => ! empty($instance['read_instance_id']),
        ));
    }
}
