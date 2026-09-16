<?php

namespace App\Support;

use App\Models\Book;
use App\Services\AuthorService;
use Illuminate\Support\Collection;

/**
 * The one rule for "is this the same book?".
 *
 * Title alone is not identity: Sylvia Plath's *Ariel* and José Enrique
 * Rodó's *Ariel* are different books, and matching on the title slug alone
 * is what merged them — the importer found Plath's row, attached Rodó and
 * his translator to it, and filed the copy underneath. But the exact author
 * set isn't identity either: a new edition with a foreword, a translator, or
 * an editor adds an author to a book without making it a new book.
 *
 * So two books are the same when their titles slug the same AND their author
 * sets share at least one author. Every ingest door that has both a title
 * and authors in hand resolves through {@see find()}; the SPA's title-only
 * first step lists {@see sameTitle()} and lets the user pick.
 */
class BookMatcher
{
    /**
     * Every book whose title slugs to the same thing as `$title`, authors
     * loaded, in creation order.
     *
     * Same-title books share a slug prefix — `ariel`, `ariel-rodo`,
     * `ariel-rodo-1` — so the prefix scan narrows the query to a handful of
     * rows, and the PHP-side re-slug of the title is what makes the match
     * exact: `ariel-s-gift` comes back from the scan and is dropped here.
     *
     * @return Collection<int, Book>
     */
    public static function sameTitle(string $title): Collection
    {
        $base = Slugger::for($title);

        if ($base === '') {
            return new Collection;
        }

        return Book::with('authors')
            ->where(function ($q) use ($base) {
                $q->where('slug', $base)->orWhere('slug', 'like', $base.'-%');
            })
            ->orderBy('book_id')
            ->get()
            ->filter(fn (Book $book) => Slugger::for($book->title) === $base)
            ->values();
    }

    /**
     * The existing book these rows describe, or null when it's a new one.
     *
     * Author entries are `{first_name, last_name}` pairs, the shape every door
     * hands `AuthorService::attachToBook`; they're compared by author slug so
     * spelling and casing differences don't count as different people. A
     * candidate with no authors at all never matches — there's nothing to
     * agree with — and neither does a call with no authors.
     *
     * @param  array<int, array<string, mixed>>  $authors
     */
    public static function find(string $title, array $authors): ?Book
    {
        $slugs = collect($authors)
            ->map(fn ($a) => AuthorService::slugFor($a['first_name'] ?? null, $a['last_name'] ?? null))
            ->filter(fn ($slug) => $slug !== '')
            ->unique();

        if ($slugs->isEmpty()) {
            return null;
        }

        return self::sameTitle($title)->first(
            fn (Book $book) => $book->authors->pluck('slug')->intersect($slugs)->isNotEmpty()
        );
    }
}
