<?php

namespace App\Http\Requests\Concerns;

use App\Services\AuthorService;

/**
 * One shape rule for author names, shared by every request that accepts them.
 *
 * **An author needs a first name or a last name, not both.** Mononyms and
 * organizations — Plato, Aristotle, National Geographic — have one name, and
 * it is a first name, because that is where a single name belongs when the
 * two columns are read apart (display, edit forms, export).
 *
 * All three book requests used to require `last_name` outright. The CSV
 * importer never did — `BulkImportService::parseAuthors` has always asked only
 * that one half be non-empty — so single-name authors could enter the catalog
 * by import and then fail validation on any subsequent edit of a book they
 * were on. The importer's rule was the right one; this trait is it, stated
 * once, so the three doors can't drift from it or from each other again.
 *
 * `tests/Feature/Authors/AuthorIngestTest` pins all four doors together.
 */
trait ValidatesAuthorNames
{
    /**
     * Rules for an array of `{first_name, last_name}` rows at `$prefix`.
     *
     * `required_without` is an implicit rule, so it still runs against a null
     * value that `nullable` would otherwise short-circuit — which is the whole
     * point here, since `ConvertEmptyStringsToNull` has already turned the
     * blank half of a mononym into null by the time rules evaluate. Laravel
     * resolves the `*` in the referenced path to the row being validated, so
     * each author is judged against its own other half rather than the array's.
     *
     * @return array<string, array<int, string>>
     */
    protected function authorNameRules(string $prefix): array
    {
        return [
            $prefix => ['sometimes', 'nullable', 'array'],
            "{$prefix}.*.first_name" => [
                "required_without:{$prefix}.*.last_name",
                'nullable',
                'string',
                'max:255',
            ],
            "{$prefix}.*.last_name" => [
                "required_without:{$prefix}.*.first_name",
                'nullable',
                'string',
                'max:255',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function authorNameMessages(string $prefix): array
    {
        $message = 'Each author needs a first name or a last name.';

        return [
            "{$prefix}.*.first_name.required_without" => $message,
            "{$prefix}.*.last_name.required_without" => $message,
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function authorNameReasonCodes(string $prefix): array
    {
        return [
            "{$prefix}.*.first_name.required_without" => 'author_name_required',
            "{$prefix}.*.last_name.required_without" => 'author_name_required',
        ];
    }

    /**
     * Trim and collapse both halves *before* the rules run, so a whitespace-only
     * name is the absence it looks like rather than a 255-character-safe string
     * that satisfies `required_without` and then normalizes to `''` on the way
     * into the database.
     *
     * @param  mixed  $authors
     * @return mixed
     */
    protected function normalizeAuthorNamesIn($authors)
    {
        if (! is_array($authors)) {
            return $authors;
        }

        return array_map(function ($author) {
            if (! is_array($author)) {
                return $author;
            }

            foreach (['first_name', 'last_name'] as $field) {
                if (! array_key_exists($field, $author) || ! is_string($author[$field])) {
                    continue;
                }

                $normalized = AuthorService::normalize($author[$field]);
                $author[$field] = $normalized === '' ? null : $normalized;
            }

            return $author;
        }, $authors);
    }
}
