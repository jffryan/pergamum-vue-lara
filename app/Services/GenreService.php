<?php

namespace App\Services;

use App\Models\Genre;
use App\Services\Exceptions\GenreInUseException;
use App\Services\Exceptions\GenreNameConflictException;
use Illuminate\Support\Facades\DB;

/**
 * Single owner of the genre name rules.
 *
 * Genres reach the database from four places: the three book-ingest paths
 * (`BookController::handleGenres`, `::updateGenres`, `NewBookController::handleGenres`,
 * all of which still call `Genre::firstOrCreate` directly) and the admin CRUD
 * surface this service backs. Consolidating the ingest paths behind an
 * `attachByName()` here is tracked in `/feature-plans/genres.md`; the shape
 * below is built so that method can drop in without moving the name rules again.
 */
class GenreService
{
    /**
     * Trim and collapse internal whitespace.
     *
     * Deliberately *not* lowercasing. Display casing is a UI concern —
     * `GenresView` and `GenreTagInput` both apply `capitalize` — and the
     * connection collation already makes case irrelevant for matching.
     */
    public static function normalize(string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', $name));
    }

    /**
     * The genre already holding this name, or null.
     *
     * NOTE: this comparison is case-insensitive and trailing-space-insensitive
     * only because `config/database.php` sets `utf8mb4_unicode_ci` — `Essays`
     * matches `essays` at the collation level, not in PHP. That leniency is
     * intended, but it would vanish silently on a collation change or a
     * non-MySQL connection, so `GenresCrudTest::test_rename_conflict_is_case_insensitive`
     * pins it. There is no unique index on `genres.name` yet (that is phase 3
     * of `/feature-plans/genre-management.md`), so this query is the only guard.
     *
     * @param  Genre|null  $except  the genre being renamed, so it doesn't collide with itself
     */
    public function findConflict(string $name, ?Genre $except = null): ?Genre
    {
        $query = Genre::where('name', $name);

        if ($except !== null) {
            $query->where('genre_id', '!=', $except->genre_id);
        }

        return $query->first();
    }

    /**
     * @throws GenreNameConflictException
     */
    public function create(string $name): Genre
    {
        $conflict = $this->findConflict($name);

        if ($conflict !== null) {
            throw new GenreNameConflictException($conflict);
        }

        return Genre::create(['name' => $name]);
    }

    /**
     * @throws GenreNameConflictException
     */
    public function rename(Genre $genre, string $name): Genre
    {
        $conflict = $this->findConflict($name, $genre);

        if ($conflict !== null) {
            throw new GenreNameConflictException($conflict);
        }

        $genre->update(['name' => $name]);

        return $genre;
    }

    /**
     * @throws GenreInUseException when books are attached and `$force` is false
     */
    public function delete(Genre $genre, bool $force): void
    {
        $booksCount = $genre->books()->count();

        if (! $force && $booksCount > 0) {
            throw new GenreInUseException($booksCount);
        }

        // `book_genre.genre_id` cascades on delete, so the pivot rows go with it.
        $genre->delete();
    }

    /**
     * Fold `$sourceIds` into `$keep`: every book tagged with a loser comes out
     * tagged with the winner, and the losers are gone.
     *
     * @param  array<int>  $sourceIds
     */
    public function merge(Genre $keep, array $sourceIds): Genre
    {
        // Merging a genre into itself would delete it. `MergeGenresRequest`
        // rejects that with a 422; this is belt-and-braces for direct callers.
        $sourceIds = array_values(array_diff(array_map('intval', $sourceIds), [$keep->genre_id]));

        if ($sourceIds === []) {
            return $keep->loadCount('books');
        }

        return DB::transaction(function () use ($keep, $sourceIds) {
            $bookIds = DB::table('book_genre')->whereIn('genre_id', $sourceIds)->pluck('book_id')->unique();
            $alreadyTagged = DB::table('book_genre')->where('genre_id', $keep->genre_id)->pluck('book_id');

            // Attach only the difference. A blind `UPDATE book_genre SET genre_id`
            // would be shorter but wrong: the pivot has no unique index on
            // (book_id, genre_id), so a book tagged with both the winner and a
            // loser would come out holding two rows for the winner. That duplicate
            // is invisible in `GenreBreakdown` (it counts DISTINCT book_id) but
            // over-reports in the `withCount('books')` the admin screen displays.
            $keep->books()->attach($bookIds->diff($alreadyTagged)->all());

            // The losers' own pivot rows cascade away with them.
            Genre::whereIn('genre_id', $sourceIds)->delete();

            return $keep->loadCount('books');
        });
    }
}
