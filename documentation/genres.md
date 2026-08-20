---
path: /documentation/
status: living
---

# Genres

## Scope

Covers the `Genre` model, the genre index/detail pages, the genre admin surface (`/admin/genres` — create, rename, delete, merge), and the autocomplete tag input used by new-book creation. Genre *attachment* during book create/update is owned by the book pipeline and lives in `books.md` — this doc summarizes the entry points and links rather than restating.

## Summary

A `Genre` is a flat taxonomy record (just `genre_id` and `name`) attached to books via the `book_genre` pivot. Genres arrive two ways: as a side effect of book creation/update via `firstOrCreate`, and directly through the CRUD surface at `/admin/genres`. They are listed alphabetically with a per-genre book count, and surfaced as a paginated bookshelf on the detail page. Unlike `Author` and `Book`, genres are routed by numeric ID, not slug — there is no `slug` column.

The admin surface exists because loose data entry produces near-duplicates — `essay` and `essays`, `sci-fi` and `scifi`. It handles that from both directions: **merge** folds N strays into one canonical genre, and **rename** reports a collision rather than creating a second row sharing a name, offering the merge inline.

## How it's wired

### Backend

- **Routes** (`routes/api.php`, all under `auth:sanctum`):
  - `Route::apiResource('/genres', GenreController::class)` — `index`, `show`, `store`, `update`, `destroy`. `apiResource` rather than `resource` because the `create` and `edit` routes only ever pointed at empty stubs.
  - `POST /genres/{genre}/merge` → `GenreController::merge`. Declared *before* the resource so the path isn't shadowed.
  - `GET /genres` → `GenreController::index` — returns every genre with `books_count`, alphabetized.
  - `GET /genres/{genre}` → `GenreController::show` — paginated list of books tagged with the genre.
- **Controllers**: `GenreController` is thin — `store`, `update`, `destroy`, and `merge` all delegate to `GenreService` and render its exceptions as 409s. The exception is `show`, which holds its own query construction (the eager-load + join + groupBy pattern is duplicated from `BookController::index` / `searchBooks`). It injects `BookService` only to call `getBooksList` for the response shape.
- **Services**: `GenreService` (`app/Services/GenreService.php`) owns the name rules — `normalize`, `findConflict`, `create`, `rename`, `delete`, `merge` for the admin surface, plus `attachByName`, `syncFromInput` and `resolveNames` for book ingest. It raises `GenreNameConflictException` and `GenreInUseException` from `app/Services/Exceptions/`, both carrying a `reasonCode` the controller renders. `BookService::getBooksList` is reused to format the per-genre book payload.
- **Requests**: `StoreGenreRequest` and `UpdateGenreRequest` (shape only — `required|string|max:255`, with `GenreService::normalize` applied in `prepareForValidation`), and `MergeGenresRequest`. All extend `ApiFormRequest`.
- **Models**: `Genre` (`genre_id` PK, fillable: `name`). `books()` is `belongsToMany(Book, 'book_genre', 'genre_id', 'book_id')->withTimestamps()` — the pivot name and FK columns are explicit because the default conventions don't match.
- **Policies / authorization**: `GenrePolicy`, registered in `AuthServiceProvider`. Every ability returns `true` — genres are global, nothing is user-scoped, and there is no admin privilege level in the app. It exists as the seam for a future gate, not as a working restriction. `GenreController::__construct` wires it via `authorizeResource`; `merge` isn't one of the seven resource verbs, so it calls `Gate::authorize` explicitly.
- **Migrations**: `2023_09_09_000006_create_genres_table.php` (just `genre_id`, `name`, timestamps — `name` is **not** unique). `2023_09_09_000007_create_book_genre_table.php` is the M2M pivot with `onDelete('cascade')` from both sides.

### Frontend

- **API layer**: `resources/js/api/GenresController.js` exports `getAllGenres()` (→ `GET /api/genres`), `getOneGenre(genre_id, options)` (→ `GET /api/genres/{id}`, with `page` and `limit` passed through `options`), and the four mutations — `createGenre(name)`, `updateGenre(genre_id, name)`, `deleteGenre(genre_id, { force })`, `mergeGenres(keep_id, source_ids)`.
- **Stores**: `GenreStore` (`stores/GenreStore.js`) holds `allGenres` — the cached index payload — plus `fetchAllGenres({ force })` and the four mutation actions. Nothing else: pagination state used to live here and no longer exists on either genre view.
- **Service**: none. Genre mutations live on the store rather than in a `services/GenreServices.js`: per the data-flow convention a service layer is for orchestration across multiple stores/controllers, and this is one domain and one API surface. The string-to-array helper `splitAndNormalizeGenres` lives in `services/BookServices.js` and is used only by the book create/edit form, not by genre views.
- **Routes** (`router/index.js`, no per-feature route file): `/genres` (`genres.index`) and `/genres/:id` (`genres.show`). Routing is by numeric ID — there is no slug column. The admin surface is `/admin/genres` (`admin.genres`), declared in `router/admin-routes.js`; see `admin.md`.
- **Views**: `views/GenresView.vue` (index — the whole catalog on one page as a responsive card grid, with a substring filter, an A–Z / most-books sort toggle, and a first-letter jump rail) and `views/GenreView.vue` (detail — server-paginated bookshelf via `BookshelfTable`).
- **Components**: `components/genres/GenreCard.vue` (one genre on the index — name, count, and the proportional bar). `components/newBook/NewGenresInput.vue` (step in the new-book wizard) wraps `components/newBook/GenreTagInput.vue` (chip-style autocomplete that warm-loads `GenreStore.allGenres` once on mount). `BookTableRow` renders the first **two** genres of each book with links to `genres.show` — its `primaryGenres` computed slices `(0, 2)`, though its own comment says three.
- **Admin components** (`components/admin/genres/`): `GenresIndex.vue` (the action root — search box, table, create form), `GenresTable.vue` / `GenreRow.vue` (name with click-to-rename inline, `books_count`, merge-selection checkbox, delete), `CreateGenre.vue`, and `MergeGenresBar.vue` (appears once ≥2 rows are checked). Destructive steps route through `components/globals/ConfirmAction.vue`.

## Non-obvious decisions and gotchas

- **`genre_id` custom PK and explicit pivot wiring.** `Genre::$primaryKey = 'genre_id'`, and `books()` passes the pivot name (`book_genre`) and FK columns (`genre_id`, `book_id`) explicitly. New relations against `Genre` must do the same; relying on Eloquent defaults will silently match on `id`.
- **No slug — routing is by numeric `genre_id`.** `Book` and `Author` both route by slug; `Genre` does not. The genre table has no `slug` column and no normalization step. The detail route is `/genres/:id` and `GenresController::show` looks up via `findOrFail($genre_id)`. Don't add `genres.show` links built from a slug.
- **Five doors, one set of name rules.** Genres reach the database from the admin CRUD surface and from four ingest paths, each of which still takes a *different input shape*:
  - `POST /books` (book create) — an array of bare strings under `book.book.genres.parsed`. The form builds it by running `splitAndNormalizeGenres` on comma-separated raw input.
  - `PUT /books/{id}` (book update) — an array of `{ genre_id?, name? }` objects.
  - `POST /create-book` (new-book wizard) — an array of `{ name }` objects.
  - `POST /bulk-upload` (CSV import) — a `;`-separated cell, split by `BulkImportService`. Easy to forget: it has no FormRequest and isn't a form.

  The shapes differ; the resulting rows must not. All four now call `GenreService::attachByName()` or `::syncFromInput()`, so `GenreService::normalize()` is the only spelling rule in the application, and blank entries / duplicate spellings resolve identically at every door. `tests/Feature/Genres/GenreIngestTest` asserts the four in parallel — a fifth door gets a block there.
- **A `genre_id` beats the name that arrives with it.** In the update shape only. The edit form renders the stored name into a text input, so a name edited next to an existing id is a rename attempt, and renaming is `GenreService::rename()`'s job — it checks for conflicts that the ingest path cannot. An id that no longer resolves falls back to the name.
- **Blank genre names are dropped, not rejected.** Every genre input in the SPA is a form row, and a form that renders an empty row has not been told about a genre. `GenreService::resolveNames` skips them, and the FormRequests mark the name `nullable` to let them through. Note *why* `nullable` is load-bearing on `book.book.genres.parsed.*`: `ConvertEmptyStringsToNull` rewrites an empty genre string to `null` before validation runs, so a bare `string` rule there 422'd the entire book over one empty row.
- **Payload-level dedupe is explicit, unlike row-level dedupe.** `GenreService::dedupeKey` lowercases in PHP. That looks redundant next to the collation, but isn't: the collation can only compare a name against a row that already exists, and two new spellings in the *same* payload never reach the database to be compared.
- **Case-only duplicates are not reachable through the application.** `config/database.php` sets `utf8mb4_unicode_ci`, a case-insensitive and trailing-space-insensitive collation, so `Genre::firstOrCreate(['name' => 'Essays'])` already matches an existing `essays` row and `where('name', $new)` is a case-insensitive conflict check for free. The lowercasing that `splitAndNormalizeGenres` and `GenreTagInput.commitInput` do is a display convention, not the dedupe. Any case-only duplicate rows in a live database came from direct SQL, not from a code path.
- **That leniency is inherited, not enforced.** Nothing in PHP implements it — a collation change or a non-MySQL connection would silently turn the conflict check off. `GenreService::findConflict` carries the caveat as a comment, and `GenresCrudTest::test_rename_conflict_is_case_insensitive` pins it.
- **`genres.name` is unique at the DB level, and the migration will refuse to build the index over dirty data.** `GenreService::findConflict` is a select-then-insert and can lose a race with a concurrent request; the unique index added by `2026_08_09_000000_add_unique_index_to_genres_name` is what actually closes that. Because MySQL would otherwise fail while creating the index, naming one offending row and leaving you to find the rest, the migration's `up()` counts duplicates first and throws listing every one, with a pointer to the merge tool. Under `utf8mb4_unicode_ci` both the index and that check are case-insensitive. **A database still holding duplicates must be cleaned at `/admin/genres` before this migration can run.**
- **`index` ordering puts numeric-prefixed genres last via raw SQL.** `orderByRaw('CASE WHEN name REGEXP "^[0-9]" THEN 2 ELSE 1 END, name')` is a MySQL-specific REGEXP. The intent is to push genres like "20th century" below the alphabetic ones; if the DB is ever swapped or the query is reused elsewhere, the REGEXP will need to be rewritten.
- **`GenreController::show` does a redundant `read_instances` join.** The query left-joins `read_instances` but never aggregates over it — only `MIN(authors.last_name)` is selected, and the join exists nowhere in the order-by or where clauses. It's a copy-paste artifact of the same scaffold used by `BookController::index` / `searchBooks`. The eager-load on `readInstances` *also* does not user-scope (no `auth()->id()` filter) — see the same caveat called out in `authors.md`. In a multi-tenant deployment this leaks read history.
- **The index doesn't paginate; the detail page does.** `GenresView` renders every genre in one pass and filters/sorts in memory — at the current scale (~140 genres, longest name 23 characters) paging was six clicks over a list that fits on one screen. `GenreView` (detail) paginates server-side via Laravel's `paginate()` and reads/writes the page through `$route.query.page`. The two views share a store but no pagination convention. Don't assume one when working in the other.

- **The index's list-shaping rules live in `utils/genreList.js`, not in the view.** `filterGenres` / `sortGenres` / `groupByLetter` / `countScale` are pure and unit-tested (`tests/utils/genreList.test.js`), which is the only way to pin them — the suite has no component-test tooling. `GenresIndex.vue` (admin) shares `filterGenres` so the two search boxes can't drift; it deliberately doesn't share the sort or the grouping.

- **The filter is a substring match, never a `RegExp`.** `GenresView` used to build `new RegExp(searchTerm, "i")` from raw input, so typing a lone `(` threw a `SyntaxError` out of a computed and blanked the page. Both search boxes now go through `filterGenres`, which lowercases and calls `includes`. Don't reintroduce a regex here.

- **`books_count` is drawn as a bar on a square-root scale, sized against the whole list.** The distribution is severely long-tailed (the largest genre holds 383 books; 39 hold exactly one), so linear bars would render the tail as a uniform sliver and a font-size tag cloud would make most of the page unreadable while encoding data as type size. `countScale` takes the full genre list, not the filtered one, so bars don't rescale under the user mid-keystroke.
- **`GenreTagInput` suggestions require ≥3 characters and cap at 3 results.** The autocomplete intentionally hides for short queries to keep the dropdown out of the way; if you're debugging "why isn't my genre showing up", that's the first thing to check. The list it filters is `GenreStore.allGenres`, which is only loaded if empty — so a long-running session with stale store state can miss recently-created genres.
- **Name conflicts are 409s from the service, not 422s from `Rule::unique`.** This is deliberate. A 422 from `ApiFormRequest` carries field/message pairs and nothing else, and the SPA needs the *colliding genre's* `genre_id` to offer "merge into it instead" without a second round-trip. So `GenreService` raises `GenreNameConflictException` carrying the `Genre`, the controller renders it as a 409 with the genre in the body, and the FormRequests validate shape only. Anything reusing these endpoints should key on `reason_code`, not on the status family.
- **Merge attaches the difference; it does not `UPDATE book_genre`.** `book_genre` has no unique constraint on `(book_id, genre_id)`, so re-pointing pivot rows with an update would leave a book tagged with *both* the winner and a loser holding two rows for the winner. That duplicate is invisible in `GenreBreakdown` (it counts `DISTINCT book_genre.book_id`) but over-reports in the `withCount('books')` the admin screen displays. `GenreService::merge` instead attaches only the books not already tagged and then deletes the losers, whose own pivot rows cascade away. `GenreMergeTest::test_a_book_tagged_with_both_winner_and_loser_keeps_exactly_one_pivot_row` is the regression guard.
- **Genres are still never auto-pruned.** Deleting a book cascades its `book_genre` rows but leaves the `Genre` row, even when its last book is gone — this differs from the orphan-prune behavior on `Author`. `DELETE /genres/{genre}` is now the way to remove one, but nothing does it automatically.
- **`books_count` on the index payload only counts pivot rows, not user-scoped reads.** `Genre::withCount('books')` runs against the global `book_genre` table; in a multi-tenant world it'll over-report. Today (single tenant) the count matches what the user sees on a genre page.
- **Every `GenreStore` mutation refetches the list; none of them patch it.** Same reasoning as `ConfigStore.createFormat`, and it matters more here: the index payload carries `books_count`, which a merge or a delete changes for rows the mutation never named, and a merge deletes rows outright. `allGenres` is also what `GenresView` and `GenreTagInput`'s autocomplete read, so a patched-in-place list would keep handing out entries pointing at deleted rows for the rest of the session. `GenreStore.test.js` pins this.
- **`GenreStore` mutations don't catch.** A 409 propagates to the caller with its body intact, because the conflict payload *is* the useful part — swallowing it would throw away the only copy of the colliding `genre_id`. Components read `e.response.data.reason_code` to tell a name conflict from an in-use delete.

## Usage notes

### Listing genres

`GET /genres` returns:

```
[
  { genre_id, name, books_count, created_at, updated_at },
  ...
]
```

Ordered alphabetically with numeric-prefixed names sorted last. The SPA caches the response in `GenreStore.allGenres`; both `GenresView` and `GenreTagInput` consume it without re-fetching. `GenreStore.fetchAllGenres({ force: true })` bypasses the cache — the admin screen uses it on mount and after every mutation.

### Fetching a genre detail page

`GET /genres/{genre_id}?page=1&limit=20` returns:

```
{
  genre: { genre_id, name },
  books: [
    {
      book: { book_id, title, slug },
      authors: [...],
      genres: [...],
      versions: [...],   // each with format eager-loaded
      read_instances: [...]
    },
    ...
  ],
  pagination: { total, perPage, currentPage, lastPage, from, to }
}
```

Books are sorted by primary author's last name (`MIN(authors.last_name)` per book). Default page size is 20; pass `?limit=` to override. The SPA links into this view via `{ name: 'genres.show', params: { id: genre_id } }` (note: ID, not slug); `BookTableRow` already does this for each book's first two genres.

### Creating, updating, and deleting genres

Two independent paths, one rulebook. Genres are still created and attached implicitly by book ingest (`POST /books`, `PUT /books/{id}`, `POST /create-book` — see `books.md` and `new-book-creation.md`); the direct CRUD API below is what `/admin/genres` drives. Both go through `GenreService`.

Names are normalized wherever they arrive: trimmed, with internal whitespace collapsed. Casing is **not** touched — display casing is a UI concern and the collation makes it irrelevant for matching. The two paths diverge only in what they do with a blank name, and the difference is intentional: the admin surface **rejects** it (a 422 from `StoreGenreRequest`, because naming a genre four spaces is a mistake worth reporting), while ingest **drops** it (an empty row in a book form is not an instruction).

`POST /genres` with `{ "name": "Essay" }` → `201` and the genre with `books_count`:

```
{ genre_id, name, books_count, created_at, updated_at }
```

`PATCH /genres/{genre}` with `{ "name": "Essays" }` → `200` and the same shape. Renaming a genre to its own current name is a success, not a conflict.

Either one, when the name is already taken, → `409`:

```
{
  reason_code: "genre_name_taken",
  reason: "A genre named \"essay\" already exists.",
  conflict: { genre_id, name, books_count, ... }
}
```

The match is case- and trailing-space-insensitive. `conflict.genre_id` is what makes the rename → merge handoff a single click.

`DELETE /genres/{genre}` → `200 { "deleted": true }` when nothing is tagged with it. When books *are* attached it is a `409` instead:

```
{ reason_code: "genre_in_use", reason: "...", books_count: 14 }
```

Re-send as `DELETE /genres/{genre}?force=true` to go through with it. Deleting is allowed on purpose — stripping a junk tag off forty books shouldn't require a merge-into-nothing workaround — but the unforced request is a server-side backstop so a mis-wired button or a stray request can't do it silently. The pivot rows cascade; the books themselves are untouched.

`POST /genres/{genre}/merge` with `{ "source_ids": [4, 9, 17] }`, where `{genre}` is the **winner**. Every book tagged with a loser comes out tagged with the winner, and the losers are deleted. Returns the winner with a fresh `books_count` reflecting the union. `source_ids` must be a non-empty array of existing `genre_id`s, none of them the winner's — otherwise `422`. The operation is transactional and **not** recoverable.

## Related

- Plan file: `/feature-plans/genres.md` — future improvements and known limitations.
- Plan file: `/feature-plans/genre-management.md` — the CRUD/merge surface's own limitations, and the unique-index migration that can only land once merge has been used against the live database.
- `/documentation/admin.md` — the admin shell that `/admin/genres` plugs into, and `ConfirmAction`.
- `/documentation/books.md` — genre attachment, update, and the comma-separated form input are owned by the book pipeline.
- `/documentation/new-book-creation.md` — the wizard step that consumes `GenreTagInput` and ships `[{name, genre_id}]` to `POST /create-book`.
- `/documentation/authors.md` — sibling taxonomy doc; many of the same gotchas (custom PK, no unique constraint, dead resource stubs) apply there. Genres no longer share the last two: they have a conflict-checked CRUD surface and no dead stubs.
- `/documentation/formats.md` — the third taxonomy doc, covering format/version dependencies.
