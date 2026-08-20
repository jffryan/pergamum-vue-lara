<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesReadDates;
use App\Http\Requests\Concerns\ValidatesAuthorNames;
use App\Rules\Rating;

/**
 * `POST /api/books` — the legacy single-form create path behind
 * `BookCreateEditForm`.
 *
 * Note the double nesting (`book.book.title`): the outer key is the envelope
 * the SPA sends, the inner one is the book's own fields alongside its
 * authors / versions / genres. `/feature-plans/books.md` item 4 tracks
 * collapsing this endpoint and `POST /api/create-book` into one; until that
 * happens, this request describes the shape rather than changing it.
 */
class StoreBookRequest extends ApiFormRequest
{
    use NormalizesReadDates;
    use ValidatesAuthorNames;

    public function rules(): array
    {
        return $this->authorNameRules('book.authors') + [
            'book' => ['required', 'array'],
            'book.book.title' => ['required', 'string', 'max:255'],

            'book.book.genres.parsed' => ['sometimes', 'nullable', 'array'],
            // `nullable`, because `ConvertEmptyStringsToNull` turns a blank
            // genre slot into `null` and `string` alone then 422'd the whole
            // book over an empty row the form itself rendered. Blanks are
            // dropped by `GenreService::resolveNames`, not rejected here.
            'book.book.genres.parsed.*' => ['nullable', 'string', 'max:255'],

            'book.versions' => ['sometimes', 'nullable', 'array'],
            // A format that doesn't exist used to be skipped silently, so a
            // typo'd id produced a book with no copies and a 200.
            'book.versions.*.format' => ['required', 'integer', 'exists:formats,format_id'],
            'book.versions.*.nickname' => ['nullable', 'string', 'max:255'],
            'book.versions.*.page_count' => ['nullable', 'integer', 'min:0'],
            'book.versions.*.audio_runtime' => ['nullable', 'integer', 'min:0'],

            'book.readInstances' => ['sometimes', 'nullable', 'array'],
            'book.readInstances.*.date_read' => ['nullable', 'date'],
            'book.readInstances.*.rating' => ['nullable', new Rating],
        ];
    }

    public function messages(): array
    {
        return $this->authorNameMessages('book.authors');
    }

    protected function reasonCodes(): array
    {
        return ['book.readInstances.*.rating.rating' => 'rating_out_of_range']
            + $this->authorNameReasonCodes('book.authors');
    }

    protected function prepareForValidation(): void
    {
        $book = $this->input('book');

        if (! is_array($book)) {
            return;
        }

        if (isset($book['authors'])) {
            $book['authors'] = $this->normalizeAuthorNamesIn($book['authors']);
        }

        if (isset($book['readInstances'])) {
            $book['readInstances'] = $this->normalizeReadDatesIn($book['readInstances']);
        }

        $this->merge(['book' => $book]);
    }

    public function title(): string
    {
        return $this->validated()['book']['book']['title'];
    }

    /** @return array<int, array{first_name: ?string, last_name: ?string}> */
    public function authors(): array
    {
        return $this->validated()['book']['authors'] ?? [];
    }

    /** @return array<int, array<string, mixed>> */
    public function versions(): array
    {
        return $this->validated()['book']['versions'] ?? [];
    }

    /** @return array<int, string> */
    public function genreNames(): array
    {
        return $this->validated()['book']['book']['genres']['parsed'] ?? [];
    }

    /**
     * Only reads that actually name a date. The create form renders a blank
     * read-instance row by default, and an empty row is not a read.
     *
     * @return array<int, array<string, mixed>>
     */
    public function readInstances(): array
    {
        return array_values(array_filter(
            $this->validated()['book']['readInstances'] ?? [],
            fn ($instance) => ! empty($instance['date_read']),
        ));
    }
}
