<?php

namespace App\Services;

use App\Models\Author;
use App\Models\Book;
use App\Support\Slugger;
use Illuminate\Support\Facades\DB;

/**
 * Single owner of the author name rules.
 *
 * Authors reach the database from four doors — `POST /books`,
 * `PUT /books/{id}`, `POST /create-book`, and the CSV importer in
 * `BulkImportService`. Each used to resolve names its own way: three called
 * `Author::firstOrCreate` on whatever the caller typed and attached with no
 * ordinal, the fourth looked the slug up by hand, deduped against the book's
 * existing authors, and continued `author_ordinal` from the current max. So
 * the same author could arrive spelled four ways, and "primary author"
 * depended on which door created the book.
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
     * Rename an author already on the book, normalizing as {@see resolve()}
     * would.
     *
     * The slug is deliberately left alone: it is the identity other rows match
     * on, and re-deriving it here could collide with the unique index and 500
     * a book edit. Renaming an author properly — re-slug, resolve the
     * collision, or merge into the author already holding the new slug — is
     * the author-edit surface tracked in `/feature-plans/authors.md`.
     *
     * @param  array<string, mixed>  $input
     */
    public function rename(Author $author, array $input): Author
    {
        $author->update([
            'first_name' => self::normalize($input['first_name'] ?? null),
            'last_name' => self::normalize($input['last_name'] ?? null),
        ]);

        return $author;
    }
}
