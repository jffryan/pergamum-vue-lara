---
path: /documentation/
status: living
---

# Genres

## Scope

Covers the `Genre` model, the genre index/detail pages, and the autocomplete tag input used by new-book creation. Genre *attachment* during book create/update is owned by the book pipeline and lives in `books.md` — this doc summarizes the entry points and links rather than restating.

## Summary

A `Genre` is a flat taxonomy record (just `genre_id` and `name`) attached to books via the `book_genre` pivot. Like `Author`, there is no dedicated genre CRUD UI: genres are created as a side effect of book creation/update via `firstOrCreate`, listed alphabetically with a per-genre book count, and surfaced as a paginated bookshelf on the detail page. Unlike `Author` and `Book`, genres are routed by numeric ID, not slug — there is no `slug` column.

## How it's wired

### Backend

- **Routes** (`routes/api.php`, all under `auth:sanctum`):
  - `Route::resource('/genres', GenreController::class)` — only `index` and `show` are implemented; `create`, `store`, `update`, and `destroy` are stub methods that return nothing.
  - `GET /genres` → `GenreController::index` — returns every genre with `books_count`, alphabetized.
  - `GET /genres/{genre_id}` → `GenreController::show` — paginated list of books tagged with the genre.
- **Controllers**: `GenreController` is mostly thin but holds its own query construction in `show` (the eager-load + join + groupBy pattern is duplicated from `BookController::index` / `searchBooks`). It injects `BookService` only to call `getBooksList` for the response shape.
- **Services**: no dedicated `GenreService`. `BookService::getBooksList` is reused to format the per-genre book payload. The genre-attachment logic lives on `BookController::handleGenres` / `updateGenres` and `NewBookController::handleGenres` (see `books.md` and `new-book-creation.md`).
- **Models**: `Genre` (`genre_id` PK, fillable: `name`). `books()` is `belongsToMany(Book, 'book_genre', 'genre_id', 'book_id')->withTimestamps()` — the pivot name and FK columns are explicit because the default conventions don't match.
- **Policies / authorization**: none. Genres are global; nothing is user-scoped.
- **Migrations**: `2023_09_09_000006_create_genres_table.php` (just `genre_id`, `name`, timestamps — `name` is **not** unique). `2023_09_09_000007_create_book_genre_table.php` is the M2M pivot with `onDelete('cascade')` from both sides.

### Frontend

- **API layer**: `resources/js/api/GenresController.js` exports `getAllGenres()` (→ `GET /api/genres`) and `getOneGenre(genre_id, options)` (→ `GET /api/genres/{id}`, with `page` and `limit` passed through `options`).
- **Stores**: `GenreStore` (`stores/GenreStore.js`) holds `allGenres` (the cached index payload) and `currentPage` (used by `GenresView`'s client-side pagination, *not* by `GenreView` which paginates server-side via the route query string).
- **Service**: none. The string-to-array helper `splitAndNormalizeGenres` lives in `services/BookServices.js` and is used only by the book create/edit form, not by genre views.
- **Routes** (`router/index.js`, no per-feature route file): `/genres` (`genres.index`) and `/genres/:id` (`genres.show`). Routing is by numeric ID — there is no slug column.
- **Views**: `views/GenresView.vue` (index — search box, sort by name or popularity, client-side pagination at 25/page) and `views/GenreView.vue` (detail — server-paginated bookshelf via `BookshelfTable`).
- **Components**: `components/newBook/NewGenresInput.vue` (step in the new-book wizard) wraps `components/newBook/GenreTagInput.vue` (chip-style autocomplete that warm-loads `GenreStore.allGenres` once on mount). `BookTableRow` renders the first three genres of each book with links to `genres.show`.

## Non-obvious decisions and gotchas

- **`genre_id` custom PK and explicit pivot wiring.** `Genre::$primaryKey = 'genre_id'`, and `books()` passes the pivot name (`book_genre`) and FK columns (`genre_id`, `book_id`) explicitly. New relations against `Genre` must do the same; relying on Eloquent defaults will silently match on `id`.
- **No slug — routing is by numeric `genre_id`.** `Book` and `Author` both route by slug; `Genre` does not. The genre table has no `slug` column and no normalization step. The detail route is `/genres/:id` and `GenresController::show` looks up via `findOrFail($genre_id)`. Don't add `genres.show` links built from a slug.
- **Three divergent ingest paths, all keyed by raw `name`.** Genres are created/attached from three places, each with a slightly different input shape:
  - `BookController::handleGenres` (book create) — input is an array of strings (from `book.genres.parsed`, which the form gets by running `splitAndNormalizeGenres` on the comma-separated raw input — trim + lowercase).
  - `BookController::updateGenres` (book update) — input is an array of `{ genre_id?, name? }` objects, with branches for "use existing ID", "fall back to name if ID is invalid", and "look up or create by name". Despite this richer shape, the SPA edit form flattens existing genres to a comma-separated string and re-parses on submit, so `genre_id` is **always** dropped on the round-trip — only the name-based branch is exercised.
  - `NewBookController::handleGenres` (new-book wizard) — input is `[{ name, genre_id }, ...]`; only `name` is used.
  All three call `Genre::firstOrCreate(['name' => $name])`. Casing inconsistency between callers will produce duplicates: the book form lowercases via `splitAndNormalizeGenres`, `GenreTagInput.commitInput` lowercases manually, but neither the controllers nor the migration enforce it. Mixed-case rows from earlier data or future callers will collide on display but not on `firstOrCreate`.
- **`genres.name` is not unique at the DB level.** `firstOrCreate` is the only dedupe. Concurrent inserts (or any future bulk-import path) can produce duplicate names. There is no merge tool.
- **`index` ordering puts numeric-prefixed genres last via raw SQL.** `orderByRaw('CASE WHEN name REGEXP "^[0-9]" THEN 2 ELSE 1 END, name')` is a MySQL-specific REGEXP. The intent is to push genres like "20th century" below the alphabetic ones; if the DB is ever swapped or the query is reused elsewhere, the REGEXP will need to be rewritten.
- **`GenreController::show` does a redundant `read_instances` join.** The query left-joins `read_instances` but never aggregates over it — only `MIN(authors.last_name)` is selected, and the join exists nowhere in the order-by or where clauses. It's a copy-paste artifact of the same scaffold used by `BookController::index` / `searchBooks`. The eager-load on `readInstances` *also* does not user-scope (no `auth()->id()` filter) — see the same caveat called out in `authors.md`. In a multi-tenant deployment this leaks read history.
- **Index vs. detail pagination are wired completely differently.** `GenresView` (index) loads the full genre list once and paginates/searches/sorts in memory at 25/page, storing `currentPage` on `GenreStore`. `GenreView` (detail) paginates server-side via Laravel's `paginate()` and reads/writes the page through `$route.query.page`. The two views share a store but no pagination convention. Don't assume one when working in the other.
- **`GenreTagInput` suggestions require ≥3 characters and cap at 3 results.** The autocomplete intentionally hides for short queries to keep the dropdown out of the way; if you're debugging "why isn't my genre showing up", that's the first thing to check. The list it filters is `GenreStore.allGenres`, which is only loaded if empty — so a long-running session with stale store state can miss recently-created genres.
- **No genre-creation API surface.** The five `Route::resource`-style stub methods on `GenreController` (`create`, `store`, `edit`, `update`, `destroy`) are intentionally empty and unreachable. Genres are only created/attached as a side effect of book creation/update; they are deleted only by the `book_genre` pivot's `onDelete('cascade')` when the parent book is deleted (and the genre row itself is *never* pruned, even when its last book is gone — this differs from the orphan-prune behavior on `Author`).
- **`books_count` on the index payload only counts pivot rows, not user-scoped reads.** `Genre::withCount('books')` runs against the global `book_genre` table; in a multi-tenant world it'll over-report. Today (single tenant) the count matches what the user sees on a genre page.

## Usage notes

### Listing genres

`GET /genres` returns:

```
[
  { genre_id, name, books_count, created_at, updated_at },
  ...
]
```

Ordered alphabetically with numeric-prefixed names sorted last. The SPA caches the response in `GenreStore.allGenres`; both `GenresView` and `GenreTagInput` consume it without re-fetching.

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

Books are sorted by primary author's last name (`MIN(authors.last_name)` per book). Default page size is 20; pass `?limit=` to override. The SPA links into this view via `{ name: 'genres.show', params: { id: genre_id } }` (note: ID, not slug); `BookTableRow` already does this for each book's first three genres.

### Creating, updating, and deleting genres

There is no direct API. Genres are created/attached by `POST /create-book` (new-book wizard, via `NewBookController::handleGenres`) and `POST /books` (`BookController::handleGenres`), and re-synced by `PATCH /books/{id}` (`BookController::updateGenres`). Genre rows themselves are never deleted by application code — the `book_genre` pivot cascades on book delete, but the `Genre` row persists even when no book references it. See `books.md` and `new-book-creation.md` for the controlling logic.

## Related

- Plan file: `/feature-plans/genres.md` — future improvements and known limitations (to be created alongside this doc; see `/feature-plans/documentation-backfill.md`).
- `/documentation/books.md` — genre attachment, update, and the comma-separated form input are owned by the book pipeline.
- `/documentation/new-book-creation.md` — the wizard step that consumes `GenreTagInput` and ships `[{name, genre_id}]` to `POST /create-book`.
- `/documentation/authors.md` — sibling taxonomy doc; many of the same gotchas (custom PK, no unique constraint, dead resource stubs) apply here too, with the slug/ID divergence as the main shape difference.
- `/documentation/formats.md` — the third taxonomy doc, covering format/version dependencies.
