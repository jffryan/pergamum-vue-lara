---
path: /feature-plans/
status: living
---

# Genres

Tracks rough edges and follow-up work for the Genres taxonomy. Descriptive content lives in `/documentation/genres.md`.

Like `Authors`, most of the gnarly behavior here is owned by the book pipeline (attach on create, sync on update). Pipeline-level work is tracked in `/feature-plans/books.md`; items below are genre-specific or cross-cut both files.

## Known limitations

### Authorization & ownership

- **No per-user ownership.** Genres are global — any authenticated user can cause a genre row to be created (via book create/update). Two users adding the same genre at the same time will collide on the `firstOrCreate` lookup; whichever insert lands first wins, and the loser silently joins it. Tracked under the broader multi-tenant gap in `/feature-plans/books.md` item 3.
- **No `GenrePolicy`.** The four `Route::resource`-style stub methods on `GenreController` (`store`, `update`, `destroy`, plus `create`/`edit`) are unreachable, so this hasn't mattered yet. The moment any of them gets implemented — especially `destroy` — a policy needs to land with it.

### Validation & request shape

- **No `FormRequest` classes.** `GenreController::show` reads `$request->input('limit', 20)` directly with no bound on the value; `?limit=999999` will happily try to paginate at that size. The genre-attachment paths in `BookController` and `NewBookController` reach into `$bookForm['book']['genres']['parsed']` / `$bookData['genres']` without validation — missing keys throw undefined-index 500s. Same pattern as books and authors; same fix applies.
- **No name validation.** Empty strings, whitespace-only names, names with thousands of characters, and names containing punctuation are all accepted by `Genre::firstOrCreate(['name' => ...])`. The frontend's `splitAndNormalizeGenres` filters empty strings *after* split, but a single comma-only input still produces no genres without surfacing an error to the user.

### Data integrity

- **`genres.name` is not unique and not indexed.** `firstOrCreate` is the only dedupe; the column has no unique index, no case-insensitive collation override, and no normalization at the DB level. Concurrent inserts can produce duplicates; case drift between callers (the form lowercases, `GenreTagInput.commitInput` lowercases, but the controllers do not) means a future caller that forgets to lowercase will silently create a near-duplicate.
- **No genre merge tool.** Once duplicates exist (case drift, typos, "sci-fi" vs "scifi" vs "science fiction"), there's no API or UI to combine them — the only fix is manual SQL on `book_genre` plus a delete on the loser.
- **No genre prune.** When the last book referencing a genre is deleted, the `book_genre` pivot rows cascade away but the `Genre` row persists. This is the inverse of the `Author` orphan-prune behavior in `BookController::destroy`, and the inconsistency is undocumented in code. Result: `genres.books_count` can read `0`, and the genre still appears in the `GenresView` index and `GenreTagInput` autocomplete.
- **Mixed-case rows from earlier data.** Because the lowercase normalization wasn't always in place, the live DB likely contains genres like `Fantasy` and `fantasy` as separate rows. Any unique-index migration will need a backfill/merge pass first.
- **No slug column.** Routing is by numeric `genre_id`, which means URLs aren't human-readable, can't be guessed, and break if the DB is ever reseeded. Adding a slug now requires a backfill plus updating every `genres.show` link site.

### Performance & query shape

- **`GenreController::show` does a redundant join + groupBy.** The query left-joins `read_instances` and groups by `books.book_id` even though nothing aggregates over read instances — only `MIN(authors.last_name)` is used. Copy-paste from `BookController::index`. The redundant join makes large genres slower than they need to be and the groupBy interacts badly with eager loads on duplicated rows.
- **`books.readInstances` is not user-scoped on the genre detail page** (called out in `genres.md`). Multi-tenant deployment would leak read history across users. Single-tenant: wasted bandwidth.
- **`GenresView` loads the full genre list and paginates client-side at 25/page.** Fine for a few hundred genres; will not be fine at scale. There's no server-side search or pagination on the index endpoint.
- **`GenreStore.allGenres` is loaded once per session and never invalidated.** Creating a new genre via book creation does not refresh the cached list — `GenreTagInput` will miss it for the rest of the session. Long sessions accumulate staleness.

### API surface

- **Resource stubs are unreachable noise.** `Route::resource('/genres', ...)` registers `store`, `update`, `destroy`, plus the unused `create` / `edit` methods, but every one of them is empty. Callers that reverse-engineer the URL pattern get a 200 with no body and no side effect — worse than a 404 because it implies the operation succeeded.
- **No `/genres` filter / search endpoint.** The index is "everything alphabetical." A search box on the index view would today need server-side support to scale; the client-side filter in `GenresView` works only because the full list fits in memory.
- **Genre attachment is split across three controllers with three input shapes** (documented in `genres.md`). A single `GenreService::attachByName($book, $names)` would consolidate the `firstOrCreate` + sync logic and stop the shapes from drifting further.

### Extensibility

- **No tests.** No coverage for the index ordering rule, the show pagination, the redundant join, or any of the three attachment paths. The frontend `splitAndNormalizeGenres` *is* covered (`tests/services/BookServices.test.js`) but nothing exercises end-to-end create → list → detail.
- **No genre metadata.** The schema is just `genre_id` + `name` + timestamps. There's no description, no parent/child hierarchy (so "Fantasy" and "Urban Fantasy" are siblings, not parent/child), no color/icon for UI affordance, no canonical-name pointer for merge handling.
- **`GenreStore` is a thin cache.** `currentPage` lives there but nothing else does — no `currentGenre`, no per-genre book cache. Anyone extending the detail page will need to flesh out the store rather than discover existing wiring (mirrors `AuthorsStore`).

### Frontend & UX

- **Sort options on `GenresView` are name and popularity only.** No "recently added", no "most read" (which would need user-scoped reads anyway), no first-letter jump nav.
- **`GenreTagInput` autocomplete requires ≥3 characters and caps at 3 results.** Both numbers are hardcoded. A user typing "ya" (Young Adult) gets nothing. The cap of 3 means longer prefixes silently hide matches.
- **`GenreTagInput` doesn't normalize on commit beyond lowercasing.** Trailing punctuation, double spaces, and Unicode look-alikes pass straight through to `firstOrCreate`. Pair with the missing name validation above.
- **`BookTableRow` shows only the first three genres.** Books with more genres surface only the first three; there's no "+N more" affordance and no link to the full list.
- **`GenreView` error message is copy-pasted from book views.** "Unable to load books at this time" — should reference the genre. Same drift as `AuthorView`.
- **No "edit genre" UI.** Fixing a typo requires manual SQL or editing every book that uses it. There's no rename endpoint and no admin surface (mirrors `Author`).
- **No genre detail header beyond the name.** `GenreView` shows `{{ genre.name }}` and a paginated bookshelf. No description, no count, no top authors, no average rating across the genre — same bookshelf-only critique as `AuthorView`.
- **Routing by ID makes URLs unshareable.** `/genres/47` is meaningless; if the user bookmarks it and the DB is reseeded, the link points elsewhere. Slugs would fix this; see Future improvements item 4.

## Future improvements

In rough priority order — earlier items unblock later ones.

1. **Add Feature tests** for `GenreController::index` (ordering rule including the numeric-suffix REGEXP), `show` (pagination, sort, the redundant-join behavior), and each of the three attachment paths in `BookController` / `NewBookController`. Necessary before any of the consolidation work below.
2. **Extract a `GenreService::attachByName($book, $names)`** and use it from `BookController::handleGenres`, `BookController::updateGenres`, and `NewBookController::handleGenres`. Lowercase + trim inside the service so casing drift can't reach the DB. Companion to the slug helper consolidation in `/feature-plans/authors.md` item 2.
3. **Make `genres.name` non-nullable, lowercase-normalize the existing data, and add a unique index.** Backfill duplicates first by merging into a single canonical row (and re-pointing `book_genre` rows). Will likely need the merge tool from item 5 to land first.
4. **Add a `slug` column on `genres`.** Backfill from `name`. Switch the route from `/genres/:id` to `/genres/:slug` and update all `genres.show` link sites (`BookTableRow`, `GenresView`). Removes the unshareable-URL gripe and aligns with `Book` / `Author`. Pair with a redirect from `/genres/:id` for any in-the-wild bookmarks.
5. **Build a genre merge tool** — `POST /genres/{keep_id}/merge/{remove_id}` that re-points `book_genre` rows from `remove_id` to `keep_id`, deletes the loser, and returns the merged record. Admin-only; needed before the unique-index migration in item 3 can land cleanly.
6. **Introduce `FormRequest` classes** for `GenreController::show` (cap `limit` at e.g. 100) and any future genre-edit endpoint. Same pattern as the books / authors flow.
7. **Build a genre edit endpoint and view.** `PATCH /genres/{id}` with a real `update` method on `GenreController`, a `GenrePolicy`, and a small edit form. Removes the "edit every book to fix a typo" workaround. Probably also a good moment to delete the unreachable resource stubs (`create`, `edit`, plus `store` / `destroy` if not implementing them yet).
8. **User-scope `books.readInstances` in `GenreController::show`.** Mirror the `auth()->id()` filter that `BookController::index` and `BookService::getBookWithRelations` apply. Required before any multi-tenant work.
9. **Drop the redundant `read_instances` join and groupBy in `GenreController::show`.** The query only needs `MIN(authors.last_name)` for sort — keep the `book_author` / `authors` join, drop the `read_instances` join, and let the eager-load do the rest. Measure before/after on the largest genre.
10. **Add server-side search to `GET /genres`.** `?q=` filter against `name`, paginated. Wire `GenresView`'s search box to it instead of the in-memory regex; keeps the index scalable as the genre count grows.
11. **Auto-prune empty genres** when their last book is deleted (or, alternatively, soft-delete with a `deleted_at` and a periodic prune job). Today the row sticks around forever. Pair with item 13 (soft delete) so a misclick is recoverable.
12. **Surface genre-level stats on the detail page.** Total books, total reads in the genre, average rating, top authors. Reuses the same aggregation logic that `StatisticsService` will end up with — coordinate with `/feature-plans/documentation-backfill.md` tier 3 (statistics) and `/feature-plans/authors.md` item 12.
13. **Soft-delete genres** once item 11 lands, so an automatic prune is recoverable. Same trait + `deleted_at` strategy as `/feature-plans/books.md` item 10 and `/feature-plans/authors.md` item 9.
14. **Invalidate `GenreStore.allGenres` after book create/update.** Either bust the cache from `BookCreateEditForm.submitCreateForm` / `submitEditForm` on success, or bump a version counter the store can watch. Removes the long-session staleness footgun in `GenreTagInput`.
15. **Loosen `GenreTagInput` thresholds.** Drop the minimum-character gate from 3 to 1, raise the result cap from 3 to ~10, and consider fuzzy match (e.g. matching "scifi" against "science fiction"). Tiny UX win for almost no code.
16. **Show all genres on `BookTableRow`, not just the first three.** Either render the full list (matching `BookCard`) or add a `+N more` chip with a popover.
17. **Add a hierarchy or alias system.** `parent_genre_id` so "Urban Fantasy" can roll up under "Fantasy", or an aliases table so "sci-fi" and "scifi" map to the same canonical row without a destructive merge. The alias path is lighter and probably the right starting point.
18. **Stabilize `GenreView`'s error message.** Currently "Unable to load books at this time"; should reference the genre. Same one-liner fix as `AuthorView`.
19. **Delete the unreachable `Route::resource` stubs from `GenreController`** once item 7 has decided which methods are real. Currently they're noise that suggests CRUD exists when it doesn't (mirrors the same cleanup in `/feature-plans/authors.md` item 16).
