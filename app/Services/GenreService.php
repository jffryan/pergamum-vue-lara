<?php

namespace App\Services;

use App\Models\Book;
use App\Models\Genre;
use App\Services\Exceptions\GenreInUseException;
use App\Services\Exceptions\GenreNameConflictException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Single owner of the genre name rules.
 *
 * Genres reach the database from five doors: the admin CRUD surface
 * (`create` / `rename` / `delete` / `merge` below) and four ingest paths —
 * `POST /books`, `PUT /books/{id}`, `POST /create-book`, and the CSV importer
 * in `BulkImportService`. Each ingest door takes a different input shape, and
 * each used to resolve names its own way, so the same genre could arrive
 * spelled four ways. They now all land on `attachByName()` /
 * `syncFromInput()`, which means `normalize()` is the only spelling rule in
 * the application. `tests/Feature/Genres/GenreIngestTest` pins that.
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
     * Attach genres named by `$names` to `$book`, creating what doesn't exist.
     *
     * The additive half of book ingest — used by the two create doors, where
     * the book is new and nothing should be removed.
     *
     * @param  array<int, string|null>  $names
     * @return Collection<int, Genre>
     */
    public function attachByName(Book $book, array $names): Collection
    {
        $genres = $this->resolveNames($names);

        // `syncWithoutDetaching`, not `attach`: `book_genre` carries no unique
        // index on (book_id, genre_id), so attaching an id the book already
        // holds writes a second pivot row rather than failing. That duplicate
        // is invisible on the book page and over-reports in `withCount('books')`.
        $book->genres()->syncWithoutDetaching($genres->pluck('genre_id')->all());

        return $genres;
    }

    /**
     * Replace `$book`'s genres with the ones `$inputs` describes.
     *
     * The edit door's shape: each entry may carry a `genre_id`, a `name`, or
     * both. A `genre_id` that resolves wins outright and the accompanying name
     * is ignored — the edit form renders the stored name into a text input, so
     * an edited name arriving next to an id is a rename attempt, and renaming
     * is the admin surface's job (`rename()` above, which checks for conflicts
     * this path cannot). An id that no longer exists falls back to the name.
     *
     * @param  array<int, array{genre_id?: int|string|null, name?: string|null}>  $inputs
     * @return Collection<int, Genre>
     */
    public function syncFromInput(Book $book, array $inputs): Collection
    {
        $ids = array_filter(array_map(fn ($input) => $input['genre_id'] ?? null, $inputs));
        $known = Genre::findMany($ids)->keyBy('genre_id');

        $resolved = collect();

        foreach ($inputs as $input) {
            $id = $input['genre_id'] ?? null;

            if ($id !== null && $known->has($id)) {
                $genre = $known->get($id);
                $resolved->put(self::dedupeKey($genre->name), $genre);

                continue;
            }

            $this->resolveInto($resolved, $input['name'] ?? null);
        }

        $genres = $resolved->values();

        $book->genres()->sync($genres->pluck('genre_id')->all());

        return $genres;
    }

    /**
     * Raw names to persisted models, in payload order, deduped.
     *
     * @param  array<int, string|null>  $names
     * @return Collection<int, Genre>
     */
    public function resolveNames(array $names): Collection
    {
        $resolved = collect();

        foreach ($names as $name) {
            $this->resolveInto($resolved, $name);
        }

        return $resolved->values();
    }

    /**
     * Resolve one raw name into the accumulator, keyed for dedupe.
     *
     * Blank names are dropped rather than rejected. Every genre input in the
     * SPA is a form row, and a form that renders an empty row has not been
     * told about a genre — the same reading `StoreBookRequest::readInstances()`
     * already applies to blank read rows.
     *
     * @param  Collection<string, Genre>  $resolved
     */
    private function resolveInto(Collection $resolved, mixed $name): void
    {
        if (! is_string($name)) {
            return;
        }

        $normalized = self::normalize($name);

        if ($normalized === '') {
            return;
        }

        $key = self::dedupeKey($normalized);

        if ($resolved->has($key)) {
            return;
        }

        // `findConflict` rather than `firstOrCreate` so the lookup rule has one
        // implementation. Both are select-then-insert and both can lose a race
        // with a concurrent request; the unique index on `genres.name` is what
        // closes that, and it is why `resolveInto` does not try to.
        $resolved->put($key, $this->findConflict($normalized) ?? Genre::create(['name' => $normalized]));
    }

    /**
     * The key two spellings must share to count as one genre.
     *
     * Lowercased explicitly. `findConflict` gets case-insensitivity free from
     * the connection collation, but that only helps once a row exists — two
     * new spellings in the *same* payload never reach the database to be
     * compared, so PHP has to make the match itself.
     */
    private static function dedupeKey(string $name): string
    {
        return mb_strtolower(self::normalize($name));
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
