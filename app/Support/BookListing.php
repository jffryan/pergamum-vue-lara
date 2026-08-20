<?php

namespace App\Support;

use App\Models\Author;
use App\Models\Book;
use App\Models\Format;
use App\Models\ReadInstance;
use App\Models\Version;
use Illuminate\Database\Eloquent\Builder;

/**
 * The shared shape of every paginated book listing: which value each column
 * sorts on, and which value the row renders.
 *
 * This lives outside the controllers because two of them need the same answer.
 * `GenreController::show` kept a private copy of the older join-and-GROUP-BY
 * ordering long enough to drift from the library — it ranked books by
 * `MIN(last_name)` (the alphabetically first author) while the table header
 * says "Primary Author", so a book by Vance and Anderson filed under A and
 * rendered as Vance. Any future author-ordered listing should build on this
 * rather than grow a third variant.
 */
class BookListing
{
    /**
     * Columns a listing can be ordered by.
     *
     * Keys are the public `?sort=` values; values are what reaches `ORDER BY`.
     * Everything but `title` resolves to a subquery alias added by
     * {@see query()}. Whitelisted rather than passed through because the value
     * lands in the query as an identifier, not a bound parameter.
     */
    public const SORTABLE = [
        'title' => 'books.title',
        'author' => 'sort_author',
        'format' => 'sort_format',
        'pages' => 'sort_pages',
        'date_read' => 'sort_date_read',
        'rating' => 'sort_rating',
    ];

    public const DEFAULT_SORT = 'author';

    /**
     * A books query with one sortable value selected per column.
     *
     * Each sort dimension is a correlated subquery rather than a join. The
     * join-and-GROUP-BY shape this replaced had to collapse the row explosion
     * with `MIN(authors.last_name)`, which meant every added select had to
     * join the `GROUP BY` too — a standing trap under MySQL 8's
     * `ONLY_FULL_GROUP_BY`. Subqueries produce one row per book to begin with,
     * so there is nothing to group and each new sortable column is one more
     * entry here.
     *
     * The read-derived subqueries matter for a second reason: they are
     * Eloquent builders, so `BelongsToCurrentUser` applies inside them. The
     * `leftJoin('read_instances', …)` they replaced was raw, and a global
     * scope constrains a model's own queries rather than a join against its
     * table — so it silently ordered and counted against every account's
     * reads. "Date read" and "rating" are only meaningful per-user, so the
     * feature and the fix are the same change.
     *
     * Eager loads are ordered to match: the row renders `authors[0]`,
     * `versions[0]` and `readInstances[0]`, so the value shown in a column has
     * to be the value sorted on, or sorting looks broken on any book with more
     * than one of something.
     */
    public static function query(): Builder
    {
        return Book::query()
            ->with([
                'authors' => fn ($q) => $q->orderBy('book_author.author_ordinal'),
                'versions' => fn ($q) => $q->orderBy('versions.version_id'),
                'versions.format',
                'genres',
                'readInstances' => fn ($q) => $q->orderByDesc('date_read'),
            ])
            ->select('books.*')
            ->addSelect([
                // Filed under `Author::sortNameExpression()`, not `last_name`,
                // so single-name authors sort by the name they have. The
                // tie-break has to use the same value: it decides which author
                // of an unordered pair the sort key comes from.
                'sort_author' => Author::selectRaw(Author::sortNameExpression())
                    ->join('book_author', 'book_author.author_id', '=', 'authors.author_id')
                    ->whereColumn('book_author.book_id', 'books.book_id')
                    ->orderBy('book_author.author_ordinal')
                    ->orderByRaw(Author::sortNameExpression())
                    ->limit(1),
                'sort_format' => Format::select('formats.name')
                    ->join('versions', 'versions.format_id', '=', 'formats.format_id')
                    ->whereColumn('versions.book_id', 'books.book_id')
                    ->orderBy('versions.version_id')
                    ->limit(1),
                'sort_pages' => Version::select('versions.page_count')
                    ->whereColumn('versions.book_id', 'books.book_id')
                    ->orderBy('versions.version_id')
                    ->limit(1),
                // Both read-derived sorts resolve against the *most recent*
                // read, so "sort by rating" ranks by how you rated it last
                // rather than by a best-ever the row never displays.
                'sort_date_read' => ReadInstance::select('read_instances.date_read')
                    ->whereColumn('read_instances.book_id', 'books.book_id')
                    ->orderByDesc('read_instances.date_read')
                    ->limit(1),
                // Ratings are stored doubled (see ReadInstance's accessor).
                // Doubling is monotonic, so ordering on the raw column is
                // correct — this value is never rendered, only sorted on.
                'sort_rating' => ReadInstance::select('read_instances.rating')
                    ->whereColumn('read_instances.book_id', 'books.book_id')
                    ->orderByDesc('read_instances.date_read')
                    ->limit(1),
            ]);
    }

    /**
     * Order a listing built by {@see query()}.
     *
     * Anything unrecognized falls back to the default rather than erroring — a
     * stale bookmark should render the library, not a 422. A listing with no
     * sort UI (the genre detail page) passes nothing and gets the default.
     */
    public static function sort(Builder $query, ?string $key = null, ?string $direction = null): Builder
    {
        $column = self::SORTABLE[strtolower((string) $key)]
            ?? self::SORTABLE[self::DEFAULT_SORT];

        $direction = strtolower((string) $direction) === 'desc' ? 'desc' : 'asc';

        // MySQL sorts NULL first ascending, which would head a "by rating"
        // list with every book you have never read. Absent values go last in
        // both directions instead.
        return $query->orderByRaw("({$column} is null) asc")
            ->orderBy($column, $direction)
            // No sort key here is unique, and pagination over a non-unique
            // ordering lets rows swap between pages. Break the tie on the PK.
            ->orderBy('books.book_id', 'asc');
    }
}
