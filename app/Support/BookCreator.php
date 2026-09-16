<?php

namespace App\Support;

use App\Models\Book;
use App\Services\AuthorService;

/**
 * Creates a book row with a slug no other book holds.
 *
 * Whether a title *should* become a new book is `BookMatcher`'s question;
 * by the time a caller is here it has decided yes. What this class settles
 * is the URL. The first book with a title gets the title's slug. A second
 * book with the same title — a different author's *Ariel* — gets the title
 * plus that author's surname (`ariel-rodo`), which reads as the
 * disambiguation it is; only when even that is taken does a numeric suffix
 * appear.
 */
class BookCreator
{
    /**
     * @param  array<int, array<string, mixed>>  $authors  `{first_name, last_name}` rows, primary author first
     */
    public static function create(string $title, array $authors = []): Book
    {
        return Book::create([
            'title' => $title,
            'slug' => self::slugFor($title, $authors),
        ]);
    }

    /**
     * The slug a book with this title and these authors would be filed under.
     *
     * `$except` is the book being renamed, so a rename doesn't collide with
     * the row it's updating.
     *
     * @param  array<int, array<string, mixed>>  $authors
     */
    public static function slugFor(string $title, array $authors = [], ?Book $except = null): string
    {
        $base = Slugger::for($title);
        $taken = self::takenSlugs($base, $except);

        if (! in_array($base, $taken, true)) {
            return $base;
        }

        $candidate = $base;
        $surname = self::surname($authors);

        if ($surname !== '') {
            $candidate = Slugger::for($title.' '.$surname);

            if (! in_array($candidate, $taken, true)) {
                return $candidate;
            }
        }

        return self::numbered($candidate, $taken);
    }

    /**
     * The disambiguating name: the primary author's surname, or their first
     * name for a mononym.
     *
     * @param  array<int, array<string, mixed>>  $authors
     */
    private static function surname(array $authors): string
    {
        $primary = $authors[0] ?? [];

        $last = AuthorService::normalize($primary['last_name'] ?? null);

        return $last !== '' ? $last : AuthorService::normalize($primary['first_name'] ?? null);
    }

    /**
     * Every slug that begins with `$base` — the base itself, the
     * author-suffixed forms, and their numbered variants — in one query.
     *
     * @return array<int, string>
     */
    private static function takenSlugs(string $base, ?Book $except): array
    {
        $query = Book::where(function ($q) use ($base) {
            $q->where('slug', $base)->orWhere('slug', 'LIKE', $base.'-%');
        });

        if ($except !== null) {
            $query->where('book_id', '!=', $except->book_id);
        }

        return $query->pluck('slug')->all();
    }

    /**
     * `$candidate-N` for the smallest N above any already in use.
     *
     * @param  array<int, string>  $taken
     */
    private static function numbered(string $candidate, array $taken): string
    {
        $highest = 0;
        $pattern = '/^'.preg_quote($candidate, '/').'-(\d+)$/';
        foreach ($taken as $slug) {
            if (preg_match($pattern, $slug, $m)) {
                $highest = max($highest, (int) $m[1]);
            }
        }

        return $candidate.'-'.($highest + 1);
    }
}
