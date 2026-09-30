<?php

namespace App\Services;

use App\Models\Author;
use App\Models\Book;
use App\Services\Exceptions\AuthorNameConflictException;
use App\Support\Slugger;
use Illuminate\Support\Facades\DB;

/**
 * Single owner of the author name rules.
 *
 * Authors reach the database from three doors — `PUT /books/{id}`,
 * `POST /create-book`, and the CSV importer in `BulkImportService`. Each used
 * to resolve names its own way: the form endpoints called
 * `Author::firstOrCreate` on whatever the caller typed and attached with no
 * ordinal, while the importer looked the slug up by hand, deduped against the
 * book's existing authors, and continued `author_ordinal` from the current
 * max. So the same author could arrive spelled several ways, and "primary
 * author" depended on which door created the book.
 *
 * They now all land on {@see resolve()} / {@see attachToBook()}, which makes
 * {@see normalize()} the only spelling rule in the application and the
 * importer's ordinal semantics the only attach rule.
 * `tests/Feature/Authors/AuthorIngestTest` pins that.
 *
 * The *shape* rule — an author needs a first name or a last name, not
 * necessarily both — is enforced one layer up, in
 * `App\Http\Requests\Concerns\ValidatesAuthorNames`, because a 422 is a
 * request concern. The two are halves of the same contract: this class
 * assumes it is handed at least one non-empty name.
 */
class AuthorService
{
    public function getAuthorWithRelations($identifier, $type = 'slug')
    {
        $query = Author::with('books.authors', 'books.genres', 'books.readInstances', 'books.versions', 'books.versions.format');

        if ($type === 'id') {
            $author = $query->where('author_id', $identifier)->firstOrFail();
        } else {
            $author = $query->where('slug', $identifier)->firstOrFail();
        }

        $authorAttributes = $author->only(['author_id', 'first_name', 'last_name', 'slug', 'bio']);

        return [
            'author' => $authorAttributes,
            'books' => $author->books->map(function ($book) {
                return [
                    'book' => $book->only(['book_id', 'title', 'slug']),
                    'authors' => $book->authors,
                    'genres' => $book->genres,
                    'versions' => $book->versions,
                    'read_instances' => $book->readInstances,
                ];
            }),
        ];
    }

    /**
     * Trim and collapse internal whitespace; absent becomes empty string.
     *
     * Deliberately *not* lowercasing, for the same reason `GenreService` isn't:
     * display casing is the author's, and `utf8mb4_unicode_ci` already makes
     * case irrelevant to matching.
     */
    public static function normalize(?string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', (string) $value));
    }

    /**
     * The slug an author with these names files under.
     *
     * One space between the halves, and `trim` so a mononym doesn't slug with
     * a leading or trailing separator — "Plato" and "|Plato" have to reach the
     * same row.
     */
    public static function slugFor(?string $first, ?string $last): string
    {
        return Slugger::for(trim(self::normalize($first).' '.self::normalize($last)));
    }

    /**
     * Find or create the author a `{first_name, last_name}` pair names.
     *
     * Matching is on the slug alone, which is what `authors.slug`'s unique
     * index actually enforces. The old `firstOrCreate(['slug', 'first_name',
     * 'last_name'])` matched on all three, so a differently-cased first name
     * missed the existing row and then tried to insert a duplicate slug — a
     * `QueryException`, not a second author, since the index landed in
     * `2026_04_30_000000_make_authors_slug_unique_and_required.php`.
     *
     * @param  array<string, mixed>  $input
     */
    public function resolve(array $input): Author
    {
        $first = self::normalize($input['first_name'] ?? null);
        $last = self::normalize($input['last_name'] ?? null);

        return Author::firstOrCreate(
            ['slug' => self::slugFor($first, $last)],
            ['first_name' => $first, 'last_name' => $last],
        );
    }

    /**
     * Resolve each entry and attach any that aren't on the book already.
     *
     * The importer's semantics, now everyone's: co-authors get sequential
     * `author_ordinal`s continuing from the book's current max, and a
     * re-attach is a no-op. The controllers used to call
     * `$book->authors()->attach($ids)` with no pivot data at all, so every
     * author on a book created through a form sat at the column default of 1 —
     * which made `authors[0]`, the name every book row renders and the library
     * sorts on, an accident of insert order.
     *
     * Returns the authors in input order, attached or not, because the create
     * responses echo them back.
     *
     * @param  array<int, array<string, mixed>>  $entries
     * @return array<int, Author>
     */
    public function attachToBook(Book $book, array $entries): array
    {
        $attachedIds = $book->authors()->pluck('authors.author_id')->all();
        $ordinal = (int) DB::table('book_author')
            ->where('book_id', $book->book_id)
            ->max('author_ordinal');

        $authors = [];

        foreach ($entries as $entry) {
            $author = $this->resolve($entry);

            if (! in_array($author->author_id, $attachedIds, true)) {
                $ordinal++;
                $book->authors()->attach($author->author_id, ['author_ordinal' => $ordinal]);
                $attachedIds[] = $author->author_id;
            }

            $authors[] = $author;
        }

        return $authors;
    }

    /**
     * Rename an author, everywhere.
     *
     * There is one `authors` row per person and every book points at it by
     * `author_id`, so the names need no propagating — what does is the slug.
     * It is the identity every ingest door finds authors by ({@see resolve()})
     * and the author page's URL, so a rename that left it on the old spelling
     * would strand both: the page would keep the old URL, and the next import
     * of the corrected name would miss this row and create a second author.
     *
     * So the slug follows the name, with two exceptions:
     *
     * - The new name slugs to a row that isn't this one. That is a statement
     *   that the two are the same person, which is a merge, not a rename —
     *   {@see AuthorNameConflictException} carries the other row so the caller
     *   can offer one.
     * - …unless the edit is cosmetic (the old and new names slug alike), in
     *   which case the author keeps the slug it has. That is what lets a row
     *   the 2026-04-30 migration de-duplicated to `john-smith-2` fix a typo
     *   without being told it collides with `john-smith`.
     *
     * Both the admin screen (`PATCH /authors/{author}`) and the book edit form
     * (`PUT /books/{id}` with an `author_id`) come through here.
     *
     * @param  array<string, mixed>  $input
     *
     * @throws AuthorNameConflictException
     */
    public function rename(Author $author, array $input): Author
    {
        $first = self::normalize($input['first_name'] ?? null);
        $last = self::normalize($input['last_name'] ?? null);
        $slug = self::slugFor($first, $last);

        if ($slug !== $author->slug) {
            $conflict = Author::where('slug', $slug)
                ->where('author_id', '!=', $author->author_id)
                ->first();

            if ($conflict === null) {
                $author->slug = $slug;
            } elseif ($slug !== self::slugFor($author->first_name, $author->last_name)) {
                throw new AuthorNameConflictException($conflict);
            }
        }

        $author->fill(['first_name' => $first, 'last_name' => $last])->save();

        return $author;
    }

    /**
     * Fold `$sourceIds` into `$keep`: every book credited to a loser comes out
     * credited to the winner, in the loser's position, and the losers are gone.
     *
     * Re-pointing the pivot row rather than detach-and-attach is what keeps
     * `author_ordinal`: a duplicate that was a book's primary author leaves
     * the winner as its primary author, not appended after the co-authors.
     * A book already crediting both keeps one row, at the better of the two
     * positions.
     *
     * @param  array<int>  $sourceIds
     */
    public function merge(Author $keep, array $sourceIds): Author
    {
        // `MergeAuthorsRequest` already refuses a self-merge; this is for
        // direct callers, since merging an author into itself deletes it.
        $sourceIds = array_values(array_diff(array_map('intval', $sourceIds), [$keep->author_id]));

        if ($sourceIds === []) {
            return $keep->loadCount('books');
        }

        return DB::transaction(function () use ($keep, $sourceIds) {
            $keepRows = DB::table('book_author')
                ->where('author_id', $keep->author_id)
                ->get()
                ->keyBy('book_id');

            $sourceRows = DB::table('book_author')
                ->whereIn('author_id', $sourceIds)
                ->orderBy('author_ordinal')
                ->get();

            foreach ($sourceRows as $row) {
                $existing = $keepRows->get($row->book_id);

                if ($existing === null) {
                    DB::table('book_author')
                        ->where('book_author_id', $row->book_author_id)
                        ->update(['author_id' => $keep->author_id, 'updated_at' => now()]);

                    $keepRows->put($row->book_id, $row);

                    continue;
                }

                if ($row->author_ordinal < $existing->author_ordinal) {
                    DB::table('book_author')
                        ->where('book_id', $row->book_id)
                        ->where('author_id', $keep->author_id)
                        ->update(['author_ordinal' => $row->author_ordinal, 'updated_at' => now()]);

                    $existing->author_ordinal = $row->author_ordinal;
                }

                DB::table('book_author')->where('book_author_id', $row->book_author_id)->delete();
            }

            Author::whereIn('author_id', $sourceIds)->delete();

            return $keep->loadCount('books');
        });
    }
}
