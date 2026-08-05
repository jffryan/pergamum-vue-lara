---
path: /feature-plans/
status: living
---

# Genres

Tracks rough edges and follow-up work for the Genres taxonomy. Descriptive content lives in `/documentation/genres.md`.

Like `Authors`, most of the gnarly behavior here is owned by the book pipeline (attach on create, sync on update). Pipeline-level work is tracked in `/feature-plans/books.md`; items below are genre-specific or cross-cut both files.

Genre CRUD + merge behind `/admin/genres` has shipped — its own follow-up work and the unique-index migration live in `/feature-plans/genre-management.md`.

## Known limitations

### Authorization & ownership

- **Genres are global, by design** (see `/documentation/books.md`). Two users adding the same genre concurrently collide on the `firstOrCreate` lookup and the loser silently joins the winner's row, which is the intended outcome for a shared taxonomy.
- **`GenrePolicy` exists but grants everything.** Every ability returns `true`, so any authenticated user can rename, delete, and merge genres globally. Tracked in `/feature-plans/genre-management.md`, which owns the admin surface's authorization exposure.

### Validation & request shape

- **`GenreController::show` has no `FormRequest`.** It reads `$request->input('limit', 20)` directly with no bound on the value; `?limit=999999` will happily try to paginate at that size. The genre-attachment paths in `BookController` and `NewBookController` reach into `$bookForm['book']['genres']['parsed']` / `$bookData['genres']` without validation — missing keys throw undefined-index 500s. Same pattern as books and authors; same fix applies. (`store`, `update`, and `merge` do have FormRequests.)
- **Name validation only covers the admin path.** `StoreGenreRequest` / `UpdateGenreRequest` normalize and bound the name, but genres arriving through book create/edit still land via `Genre::firstOrCreate(['name' => ...])` with no validation at all — empty strings, thousands of characters, and arbitrary punctuation all pass. The frontend's `splitAndNormalizeGenres` filters empty strings *after* split, but a single comma-only input still produces no genres without surfacing an error to the user. Consolidating the two doors is `/feature-plans/genre-management.md` item 2.

### Data integrity

- **`genres.name` is not unique and not indexed.** The column has no unique index and no normalization at the DB level. `firstOrCreate` is the only dedupe on the three book-ingest paths; the admin CRUD path adds `GenreService::findConflict`. Concurrent inserts can still produce duplicates. The unique index is `/feature-plans/genre-management.md` item 1, and it can only land once the merge tool has cleaned the live data.
- **Case-only duplicates are *not* reachable through the application.** `config/database.php` sets `utf8mb4_unicode_ci`, a case- and trailing-space-insensitive collation, so `firstOrCreate(['name' => 'Essays'])` already matches an existing `essays` row and `where('name', $new)` is a case-insensitive conflict check for free. The lowercasing in `splitAndNormalizeGenres` and `GenreTagInput.commitInput` is a display convention, not the dedupe. Any case-only duplicates in the live database came from the direct-SQL management the admin surface replaced. The exposure that remains is that the leniency is inherited from the collation rather than implemented — see `/feature-plans/genre-management.md`.
- **No genre prune.** When the last book referencing a genre is deleted, the `book_genre` pivot rows cascade away but the `Genre` row persists. This is the inverse of the `Author` orphan-prune behavior in `BookController::destroy`, and the inconsistency is undocumented in code. Result: `genres.books_count` can read `0`, and the genre still appears in the `GenresView` index and `GenreTagInput` autocomplete. `/admin/genres` is now the manual cleanup path, but nothing prunes automatically.
- **No slug column.** Routing is by numeric `genre_id`, which means URLs aren't human-readable, can't be guessed, and break if the DB is ever reseeded. Adding a slug now requires a backfill plus updating every `genres.show` link site.

### Performance & query shape

- **`GenreController::show` does a redundant join + groupBy.** The query left-joins `read_instances` and groups by `books.book_id` even though nothing aggregates over read instances — only `MIN(authors.last_name)` is used. Copy-paste from `BookController::index`. The redundant join makes large genres slower than they need to be and the groupBy interacts badly with eager loads on duplicated rows.
- ~~**`books.readInstances` is not user-scoped on the genre detail page.**~~ Fixed by `App\Models\Scopes\BelongsToCurrentUser` on `ReadInstance`, which scopes the eager load in `GenreController::show`. Pinned by `tests/Feature/UserScoping/TaxonomyScopingTest`. The `read_instances` **join** in the same query is still unscoped — a global scope doesn't reach a raw join — but it only inflates the grouped row count; see item 9.
- **`GenresView` loads the full genre list and paginates client-side at 25/page.** Fine for a few hundred genres; will not be fine at scale. There's no server-side search or pagination on the index endpoint.
- **`GenreStore.allGenres` is loaded once per session and never invalidated.** Creating a new genre via book creation does not refresh the cached list — `GenreTagInput` will miss it for the rest of the session. Long sessions accumulate staleness.

### API surface

- **No `/genres` filter / search endpoint.** The index is "everything alphabetical." Both the `GenresView` search box and the `/admin/genres` one filter in memory, which works only because the full list fits there.
- **Genre attachment is split across three controllers with three input shapes** (documented in `genres.md`), none of which go through `GenreService`. Consolidating them behind `GenreService::attachByName($book, $names)` is `/feature-plans/genre-management.md` item 2.

### Extensibility

- **Read and ingest paths are thinly tested.** `GenresResourceTest` smoke-tests `index` and `show`, and `GenresCrudTest` / `GenreMergeTest` cover the admin surface, but nothing pins the index ordering rule (the numeric-prefix REGEXP), the `show` pagination and redundant join, or any of the three attachment paths. The frontend `splitAndNormalizeGenres` *is* covered (`tests/services/BookServices.test.js`) but nothing exercises end-to-end create → list → detail.
- **No genre metadata.** The schema is just `genre_id` + `name` + timestamps. There's no description, no parent/child hierarchy (so "Fantasy" and "Urban Fantasy" are siblings, not parent/child), no color/icon for UI affordance, no canonical-name pointer for merge handling.
- **`GenreStore` is a thin cache.** `currentPage` lives there but nothing else does — no `currentGenre`, no per-genre book cache. Anyone extending the detail page will need to flesh out the store rather than discover existing wiring (mirrors `AuthorsStore`).

### Frontend & UX

- **Sort options on `GenresView` are name and popularity only.** No "recently added", no "most read" (which would need user-scoped reads anyway), no first-letter jump nav.
- **`GenreTagInput` autocomplete requires ≥3 characters and caps at 3 results.** Both numbers are hardcoded. A user typing "ya" (Young Adult) gets nothing. The cap of 3 means longer prefixes silently hide matches.
- **`GenreTagInput` doesn't normalize on commit beyond lowercasing.** Trailing punctuation, double spaces, and Unicode look-alikes pass straight through to `firstOrCreate`. Pair with the missing name validation above.
- **`BookTableRow` shows only the first two genres.** There's no "+N more" affordance and no link to the full list. Note that `primaryGenres` slices `(0, 2)` while its own comment says "first 3 genres" — whichever is intended, one of them is wrong.
- **`GenreView` error message is copy-pasted from book views.** "Unable to load books at this time" — should reference the genre. Same drift as `AuthorView`.
- **Renaming is admin-only and out of the way.** `/admin/genres` is the only place to fix a typo; there's no affordance from `GenreView` or from a book's edit form, so noticing a bad genre and fixing it are two separate journeys.
- **No genre detail header beyond the name.** `GenreView` shows `{{ genre.name }}` and a paginated bookshelf. No description, no count, no top authors, no average rating across the genre — same bookshelf-only critique as `AuthorView`.
- **Routing by ID makes URLs unshareable.** `/genres/47` is meaningless; if the user bookmarks it and the DB is reseeded, the link points elsewhere. Slugs would fix this; see Future improvements item 3.

## Future improvements

In rough priority order — earlier items unblock later ones.

1. **Add Feature tests** for `GenreController::index` (ordering rule including the numeric-suffix REGEXP), `show` (pagination, sort, the redundant-join behavior), and each of the three attachment paths in `BookController` / `NewBookController`. Necessary before any of the consolidation work below. The admin CRUD and merge surface is already covered by `tests/Feature/Genres/`.
2. **Extract a `GenreService::attachByName($book, $names)`** and use it from `BookController::handleGenres`, `BookController::updateGenres`, and `NewBookController::handleGenres`. The service exists and owns the name rules already; this is about routing the three ingest paths through it. Tracked in detail as `/feature-plans/genre-management.md` item 2, and a companion to the slug helper consolidation in `/feature-plans/authors.md` item 2.
3. **Add a `slug` column on `genres`.** Backfill from `name`. Switch the route from `/genres/:id` to `/genres/:slug` and update all `genres.show` link sites (`BookTableRow`, `GenresView`). Removes the unshareable-URL gripe and aligns with `Book` / `Author`. Pair with a redirect from `/genres/:id` for any in-the-wild bookmarks. Note that renaming a genre would then move its URL — decide whether the slug follows the name or is frozen at creation.
4. **Introduce a `FormRequest` for `GenreController::show`** (cap `limit` at e.g. 100). Same pattern as the books / authors flow; the write endpoints already have theirs.
5. ~~**User-scope `books.readInstances` in `GenreController::show`.**~~ Shipped — see the Known limitations entry above.
6. **Drop the redundant `read_instances` join and groupBy in `GenreController::show`.** The query only needs `MIN(authors.last_name)` for sort — keep the `book_author` / `authors` join, drop the `read_instances` join, and let the eager-load do the rest. Measure before/after on the largest genre.
7. **Add server-side search to `GET /genres`.** `?q=` filter against `name`, paginated. Wire the `GenresView` and `/admin/genres` search boxes to it instead of their in-memory filters; keeps both scalable as the genre count grows.
8. **Auto-prune empty genres** when their last book is deleted (or, alternatively, soft-delete with a `deleted_at` and a periodic prune job). Today the row sticks around forever. Pair with item 10 (soft delete) so a misclick is recoverable.
9. **Surface genre-level stats on the detail page.** Total books, total reads in the genre, average rating, top authors. These are `ScopeResolver` cases plus a surface config — see `/feature-plans/statistics-widgets.md` item 1.
10. **Soft-delete genres** once item 8 lands, so an automatic prune is recoverable. Same trait + `deleted_at` strategy as `/feature-plans/books.md` item 10 and `/feature-plans/authors.md` item 9. Would also make the admin delete and merge recoverable — see `/feature-plans/genre-management.md` item 4.
11. **Invalidate `GenreStore.allGenres` after book create/update.** Either bust the cache from `BookCreateEditForm.submitCreateForm` / `submitEditForm` on success, or bump a version counter the store can watch. Removes the long-session staleness footgun in `GenreTagInput`. The admin mutations already force a refetch; this is the book-pipeline half of the same problem.
12. **Loosen `GenreTagInput` thresholds.** Drop the minimum-character gate from 3 to 1, raise the result cap from 3 to ~10, and consider fuzzy match (e.g. matching "scifi" against "science fiction"). Tiny UX win for almost no code.
13. **Show all genres on `BookTableRow`, not just the first three.** Either render the full list (matching `BookCard`) or add a `+N more` chip with a popover.
14. **Add a hierarchy or alias system.** `parent_genre_id` so "Urban Fantasy" can roll up under "Fantasy", or an aliases table so "sci-fi" and "scifi" map to the same canonical row without a destructive merge. The alias path is lighter and probably the right starting point — and unlike merge, it's reversible.
15. **Stabilize `GenreView`'s error message.** Currently "Unable to load books at this time"; should reference the genre. Same one-liner fix as `AuthorView`.
