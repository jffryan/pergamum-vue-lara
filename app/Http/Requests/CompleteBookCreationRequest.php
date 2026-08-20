<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesReadDates;
use App\Http\Requests\Concerns\ValidatesAuthorNames;
use App\Models\Format;
use App\Rules\Rating;

/**
 * `POST /api/create-book` — the final step of the multi-step create flow.
 *
 * A version row is either a reference to an existing copy (`version_id`) or a
 * new one (`format.format_id`), never both and never neither. The rest of the
 * payload is the same graph the single-form endpoint builds, under different
 * key names — `/feature-plans/books.md` item 4 tracks reconciling them.
 */
class CompleteBookCreationRequest extends ApiFormRequest
{
    use NormalizesReadDates;
    use ValidatesAuthorNames;

    public function rules(): array
    {
        return $this->authorNameRules('bookData.authors') + [
            'bookData' => ['required', 'array'],
            'bookData.book.title' => ['required', 'string', 'max:255'],

            'bookData.genres' => ['sometimes', 'nullable', 'array'],
            // `nullable`, not `required`: a blank genre row is a form artifact,
            // and `GenreService::resolveNames` drops it. Matches the other two
            // ingest doors — see `tests/Feature/Genres/GenreIngestTest`.
            'bookData.genres.*.name' => ['nullable', 'string', 'max:255'],

            'bookData.versions' => ['sometimes', 'nullable', 'array'],
            'bookData.versions.*.version_id' => ['nullable', 'integer', 'exists:versions,version_id'],
            // An unknown format used to throw a bare \Exception caught by the
            // controller's rollback, which reported it as a 200 with
            // success:false. It is a bad request, so say so.
            'bookData.versions.*.format.format_id' => [
                'required_without:bookData.versions.*.version_id',
                'nullable',
                'integer',
                'exists:formats,format_id',
            ],
            'bookData.versions.*.nickname' => ['nullable', 'string', 'max:255'],
            'bookData.versions.*.page_count' => ['nullable', 'integer', 'min:0'],
            'bookData.versions.*.audio_runtime' => ['nullable', 'integer', 'min:0'],

            'bookData.read_instances' => ['sometimes', 'nullable', 'array'],
            'bookData.read_instances.*.version_id' => ['nullable', 'integer', 'exists:versions,version_id'],
            'bookData.read_instances.*.date_read' => ['nullable', 'date'],
            'bookData.read_instances.*.rating' => ['nullable', new Rating],
        ];
    }

    public function messages(): array
    {
        return $this->authorNameMessages('bookData.authors');
    }

    protected function reasonCodes(): array
    {
        return ['bookData.read_instances.*.rating.rating' => 'rating_out_of_range']
            + $this->authorNameReasonCodes('bookData.authors');
    }

    protected function prepareForValidation(): void
    {
        $bookData = $this->input('bookData');

        if (! is_array($bookData)) {
            return;
        }

        if (isset($bookData['authors'])) {
            $bookData['authors'] = $this->normalizeAuthorNamesIn($bookData['authors']);
        }

        if (isset($bookData['read_instances'])) {
            $bookData['read_instances'] = $this->normalizeReadDatesIn($bookData['read_instances']);
        }

        $this->merge(['bookData' => $bookData]);
    }

    public function title(): string
    {
        return $this->validated()['bookData']['book']['title'];
    }

    /** @return array<int, array<string, mixed>> */
    public function authors(): array
    {
        return $this->validated()['bookData']['authors'] ?? [];
    }

    /** @return array<int, string> */
    public function genreNames(): array
    {
        return array_column($this->validated()['bookData']['genres'] ?? [], 'name');
    }

    /**
     * Version rows split into the two kinds the flow supports.
     *
     * New rows come back as attribute arrays with their length fields already
     * reduced to what the format carries, so the controller doesn't have to
     * re-derive that. `book_id` is the caller's to fill in — the book does
     * not exist yet when this runs.
     *
     * @return array{existing: array<int, int>, new: array<int, array<string, mixed>>}
     */
    public function versions(): array
    {
        $existing = [];
        $new = [];

        foreach ($this->validated()['bookData']['versions'] ?? [] as $version) {
            if (! empty($version['version_id'])) {
                $existing[] = (int) $version['version_id'];

                continue;
            }

            $format = Format::findOrFail($version['format']['format_id']);

            $new[] = [
                'format_id' => $format->format_id,
                'nickname' => $version['nickname'] ?? null,
            ] + $format->lengthFieldsFrom($version);
        }

        return ['existing' => $existing, 'new' => $new];
    }

    /** @return array<int, array<string, mixed>> */
    public function readInstances(): array
    {
        return $this->validated()['bookData']['read_instances'] ?? [];
    }
}
