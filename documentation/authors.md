---
path: /documentation/
status: living
---

# Authors

## Scope

Covers the `Author` model, the slug-routed author detail page, and the find-or-stub helper exposed for the book-creation flow. Author *attachment* during book create/update and the orphan-pruning that runs on book delete are owned by the book pipeline and are documented in `books.md` — this doc links to them rather than restating.

## Summary

An `Author` is a flat taxonomy record (first name, last name, slug) attached to books via the `book_author` pivot. There is no dedicated author CRUD UI: authors are created as a side effect of book creation/update, surfaced read-only on a per-author detail page, and pruned automatically when their last book is deleted.

## How it's wired

### Backend

- **Routes** (`routes/api.php`, all under `auth:sanctum`):
  - `GET /author/{slug}` → `AuthorController::getAuthorBySlug` — slug-based detail lookup, the SPA's only entrypoint to an author.
  - `POST /create-authors` → `AuthorController::getOrSetToBeCreatedAuthorsByName` — given a list of `{ name, first_name, last_name }`, returns either the existing `Author` row (matched by slug) or a stub object with `author_id: null`. Designed for the book-creation flow's "find existing vs. stub new" branching; see `new-book-creation.md`. Currently no frontend caller — the live new-book flow handles authors inline through `BookController::store` / `NewBookController::createBook`.
  - No `Route::resource('authors', …)` — the `index`, `store`, `update`, and `destroy` methods on `AuthorController` are stubs and unreachable.
- **Controllers**: `AuthorController` is thin — `show` and `getAuthorBySlug` both delegate to the service; `getOrSetToBeCreatedAuthorsByName` holds its own slug-and-lookup logic inline.
- **Services**: `app/Services/AuthorService.php`. The canonical entrypoint is `getAuthorWithRelations($identifier, 'id'|'slug')`, which eager-loads `books.authors`, `books.genres`, `books.readInstances`, `books.versions`, and `books.versions.format` and shapes the response as `{ author: { author_id, first_name, last_name, slug, bio }, books: [{ book, authors, genres, versions, read_instances }] }`. Any new code surfacing an author payload should call this rather than re-implementing the eager-load shape.
- **Models**: `Author` (`author_id` PK, fillable: `first_name`, `last_name`, `slug`). `books()` is `belongsToMany(Book, 'book_author', 'author_id', 'book_id')->withTimestamps()` — the pivot name and FK columns are explicit because the default conventions don't match.
- **Policies / authorization**: none. Author records are global; nothing is user-scoped.
- **Migrations**: `2023_09_09_000002_create_authors_table.php` (note `last_name` is required, `first_name` and `slug` are nullable, and `slug` has no unique constraint — collisions are possible and `firstOrCreate` is what prevents duplicates in practice). `2023_09_09_000003_create_book_author_table.php` is the M2M pivot.

### Frontend

- **API layer**: `resources/js/api/AuthorController.js` exports `getAuthorBySlug(slug)` (used) and `getOneAuthor(author_id)` (unused — points at `/api/authors/{id}`, which has no backend route).
- **Stores**: `AuthorsStore` (`stores/AuthorsStore.js`) holds `currentAuthor`, plus unused `allAuthors` and `sortedBy` slots. Only `setCurrentAuthor` is wired up.
- **Service**: none dedicated. Author data shaping for the book-creation flow lives in `services/BookServices.js` and `stores/NewBookStore.js`.
- **Routes**: `router/author-routes.js` — single route `/authors/:slug` (named `authors.show`). Routing is slug-only; there is no ID-based route.
- **Views**: `views/AuthorView.vue` — fetches via `getAuthorBySlug`, stores result in `AuthorsStore.currentAuthor`, and renders the author's books through the shared `BookshelfTable`.
- **Components**: no author-specific components. The detail page reuses `BookshelfTable`, and book-creation author input lives in `components/newBook/NewAuthorsInput.vue` (covered in `new-book-creation.md`).

## Non-obvious decisions and gotchas

- **`author_id` custom PK and explicit pivot wiring.** `Author::$primaryKey = 'author_id'`, and `books()` passes the pivot name (`book_author`) and FK columns (`author_id`, `book_id`) explicitly. New relations against `Author` must do the same; relying on Eloquent defaults will silently match on `id`.
- **Slug normalization is duplicated and inconsistent across call sites.** Three different normalizers exist:
  - `AuthorController::getOrSetToBeCreatedAuthorsByName` — lowercases, strips non-alphanumerics, replaces spaces with hyphens, caps at **30** chars.
  - `BookController::handleAuthors` — lowercases, collapses whitespace, replaces spaces with hyphens. **Does not** strip non-alphanumerics and **has no length cap**.
  - `NewBookController::handleAuthors` — same as `BookController::handleAuthors` but adds the non-alphanumeric strip. Still no length cap.
  An author named "O'Neil" produces three different slugs depending on which path created the record, so `firstOrCreate` can fail to dedupe. If you change the rule, change all three (and consider whether the existing data needs a backfill). The 30-char cap on the create-authors endpoint also disagrees with the book paths' uncapped slugs.
- **`firstOrCreate` is the only dedupe.** The `slug` column is not unique at the DB level, so concurrent inserts can produce duplicates. Practically this hasn't bitten the app because book creation isn't concurrent per user, but anything batch (bulk upload, future imports) should be aware.
- **`getOrSetToBeCreatedAuthorsByName` is a find-or-stub, not a writer.** Despite the route name `/create-authors`, this endpoint does not persist anything for new authors — it returns a stub with `author_id: null` that the caller is expected to round-trip back into `POST /create-book`, which is what actually inserts the row. Don't add side-effecting persistence here without auditing callers.
- **No author CRUD surface.** The five `Route::resource`-style stubs on `AuthorController` (`index`, `create`, `store`, `edit`, `update`, `destroy`) are intentionally empty and unreachable. Authors are created/updated as a side effect of `BookController::store` / `BookController::updateAuthors` / `NewBookController::createBook`; they are deleted only via the orphan-prune branch in `BookController::destroy` (see `books.md` for that flow).
- **`AuthorService::getAuthorWithRelations` does not user-scope `readInstances`.** The eager-load on `books.readInstances` pulls every user's reads for every book on the author's page. Books and versions are global (matching the convention in `books.md`), but read history elsewhere in the app *is* scoped via `auth()->id()` — the author detail surface is the exception. Treat this as load-bearing for the current "related books by same author" view; if you start surfacing per-user read state on this page, add the user-scope filter.
- **`bio` is returned but not stored.** `getAuthorWithRelations` includes `bio` in the response, but the migration has no `bio` column and the model doesn't declare it. The field reads as `null` today; if you wire bio in, add a migration before frontend code starts depending on it.
- **Frontend `getOneAuthor` is dead code.** It builds `/api/authors/{id}`, which has no backing route. Use `getAuthorBySlug`; remove `getOneAuthor` if you touch the file.
- **`AuthorsStore` is mostly aspirational.** `allAuthors` and `sortedBy` exist but are never written or read. The store is effectively a single-slot holder for `currentAuthor`. If you add a list/index view, expect to flesh the store out rather than discover existing wiring.

## Usage notes

### Fetching an author detail page

`GET /author/{slug}` returns:

```
{
  author: { author_id, first_name, last_name, slug, bio },
  books: [
    {
      book: { book_id, title, slug },
      authors: [...],
      genres: [...],
      versions: [...],   // each with format eager-loaded
      read_instances: [...]
    },
    ...
  ]
}
```

The SPA links into this view via `{ name: 'authors.show', params: { slug } }`; `BookTableRow` and `ListItemsTable` already do this for the primary author of each book.

### Find-or-stub during book creation

`POST /create-authors` with `{ authorsData: [{ name, first_name, last_name }, ...] }` returns `{ authors: [...] }` where each entry is either a full `Author` row (existing match by slug) or `{ author_id: null, first_name, last_name, slug }` (stub). Stubs are completed by passing the same shape back into `POST /create-book`, which calls `firstOrCreate` and persists. The current SPA doesn't call this endpoint — it sends the raw author input through to `POST /create-book` directly — but it remains the contract for any caller that wants to confirm matches before committing.

### Creating, updating, and deleting authors

There is no direct API. Authors are created/attached by `POST /create-book` and `POST /books`, updated by `PATCH /books/{id}` (via `BookController::updateAuthors`), and pruned to zero books by `DELETE /books/{id}` (which returns `deleted_authors` on the response). See `books.md` for the controlling logic.

## Related

- Plan file: `/feature-plans/authors.md` — future improvements and known limitations (to be created alongside the rest of the documentation backfill; see `/feature-plans/documentation-backfill.md`).
- `/documentation/books.md` — author attachment, update, and orphan-prune are owned by the book pipeline.
- `/documentation/new-book-creation.md` — the multi-step creation flow that consumes `/create-authors` (or bypasses it).
- `/documentation/genres.md`, `/documentation/formats.md` — sibling taxonomy docs.
