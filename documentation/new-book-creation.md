---
path: /documentation/
status: living
---

# New book creation

## Scope

Covers the multi-step "New book" flow rooted at `/new-book/` (`NewBookView` + `NewBookStore` + `components/newBook/*` + `NewBookController`). This is the primary path users take to add a book via the SPA. The single-form `/add-books` route (`AddBooksView` → `BookCreateEditForm`, posting to `BookController::store`) is a separate, older code path covered in `books.md`. The "add a version to an existing book" path (`/books/:slug/new-version` → `AddVersionView`) and the "add read history to an existing book" path (`/books/:slug/add-read-history` → `AddReadHistoryView`) reuse `NewBookStore` but are described in `books.md` and `read-history.md` respectively.

## Summary

A two-request flow with a client-side state machine in between. Step 1 sends just the title to the backend, which slugs it and looks for an existing match. The response branches the SPA into one of two paths — "this is a new book, collect authors/genres/versions/read-instances and submit it all at once" or "a book with this title exists, decide whether to add a version to it or force a new book with a colliding title". The store drives step transitions by swapping which components the route renders; components don't navigate, they push data to the store and let the store pick the next step.

## How it's wired

### Backend

- **Routes** (`routes/api.php`, all under `auth:sanctum`):
  - `POST /create-book/title` → `NewBookController::createOrGetBookByTitle` — slug-and-lookup. Returns `{ exists: bool, book }`. When `exists: true`, `book` is a full `Book` with `authors`, `genres`, `versions`, `versions.format`, and `versions.readInstances` (user-scoped via `auth()->id()`); when `false`, `book` is the unsaved `{ title, slug }` pair the SPA will round-trip back.
  - `POST /create-book` → `NewBookController::completeBookCreation` — accepts the full `bookData` payload (`book`, `authors`, `genres`, `versions`, `read_instances`) and persists everything inside a single `DB::transaction`.
- **Controllers**: `NewBookController` is *not* thin — it holds slug normalization, unique-slug generation, and per-relation `handle*` helpers inline rather than delegating to a service.
- **Services**: none dedicated. `BookService` / `AuthorService` are not involved in the create path; this controller has its own slug logic and `firstOrCreate` calls.
- **Models**: creates `Book`, attaches `Author` and `Genre` rows (via `firstOrCreate`), creates `Version` rows (with `format_id` resolved from the request payload), and creates `ReadInstance` rows scoped to `auth()->id()`. All custom `_id` PKs apply (see `books.md`).
- **Policies / authorization**: none beyond `auth:sanctum`. Anyone authenticated can create a book and the global taxonomy rows it spawns.
- **Migrations**: no migrations specific to this flow — it writes the same tables documented in `books.md`, `authors.md`, `genres.md`, `formats.md`.

### Frontend

- **API layer**: `resources/js/api/BookController.js` — `createOrGetBookByTitle(title)` → `POST /api/create-book/title` and `submitNewBook(bookData)` → `POST /api/create-book`. Both use `makeRequest` + `buildUrl`.
- **Stores**: `NewBookStore` (`stores/NewBookStore.js`). Holds `currentBookData` (book/authors/genres/read_instances/versions) and `currentStep` (`{ heading, component: [string, …] }`). Despite the name, this store is also reused by `AddVersionView`, `AddReadHistoryView`, and `UpdateBookReadInstance` — see Non-obvious decisions.
- **Service**: none for the create flow. `services/BookServices.js` exists but only exposes utilities (`addVersionToBookService`, `calculateRuntime`, `fetchBookData`, `formatDateRead`, `splitAndNormalizeGenres`); none are used by the new-book flow.
- **Routes**: `router/book-routes.js` — `/new-book/` (named `books.new`) is the entrypoint linked from `SidebarNav`. `/add-books` (named `books.create`) is a *different* legacy route that renders `BookCreateEditForm`; it is not part of this flow.
- **Views**: `views/NewBookView.vue` — a thin shell that reads `NewBookStore.currentStep` and renders the listed components dynamically with `<component :is>`.
- **Components** (`resources/js/components/newBook/`):
  - `NewBookTitleInput` — step 1, posts to `/create-book/title`.
  - `NewBookVersionConfirmation` — only rendered when the title hits an existing book.
  - `NewAuthorsInput` — multi-row author entry.
  - `NewGenresInput` + `GenreTagInput` — tag-style genre entry; see `genres.md`.
  - `NewVersionsInput` — format/page count/audio runtime/nickname + an `is_read` toggle that branches the next step.
  - `NewReadInstanceInput` — date + rating; only shown when `is_read` is true.
  - `NewBookSubmitControls` — final review + submit.
  - `NewBookProgressForm` — read-only summary of `currentBookData` rendered alongside most steps so the user sees their progress.

## Non-obvious decisions and gotchas

- **Two unrelated "create book" routes coexist.** `/new-book/` (this flow) and `/add-books` (a single-form `BookCreateEditForm` posting to `POST /books`) are both reachable, share no code, and produce books in different ways. Only `/new-book/` is linked from the sidebar; `/add-books` is effectively dead UI but still routed. Don't assume changes to one path apply to the other.
- **The store, not the components, drives navigation.** `NewBookView` renders `currentStep.component` (an array of component names) via `<component :is>`. Each form component submits to a `NewBookStore` action which mutates `currentBookData` and then calls `setStep([...])` to advance. There's no `<router-view>` and no per-step URLs — the back button does *not* return to a previous step, it leaves the flow entirely. If a user reloads mid-flow, state is lost (`created()` calls `resetStore`).
- **`NewBookStore` is misnamed — it's also the "current book being mutated" store.** `AddReadHistoryView` and `AddVersionView` call `setBookFromExisting(currentBook)` to load an existing book into it; `UpdateBookReadInstance` calls `addReadInstanceToExistingBookVersion`. Renaming or splitting it requires touching those callers.
- **The two-step backend handshake is not idempotent.** `POST /create-book/title` only reads (it returns a stub on miss, no row is created). `POST /create-book` is what writes. If the user advances past the title step, drops the network, and retries, the second `POST /create-book` will run `Book::create` again and rely on `generateUniqueSlug` to dodge the collision — producing a second book with the same title and a `-1` slug. There's no client-side guard.
- **`generateUniqueSlug` does a `LIKE 'base%'` scan, not a unique-constraint check.** It pulls all matching slugs, regexes out the highest `-N` suffix, and appends `N+1`. Cheap at current scale; not safe under concurrent inserts (two simultaneous creates can both compute `-3`). The `slug` column has no DB-level unique index to back this up.
- **`resetToAuthors` sets a random 5-7 char slug to "avoid conflicts".** When the user picks "Create New Book" on the duplicate-title screen, the store assigns a `Math.random().toString(36).substring(7)` slug instead of the title-derived one. This works because `createBook` regenerates a real slug server-side and only uses the request slug if it matches the regenerated one — but the mechanism is opaque from reading either side alone. Don't rely on the client-supplied slug elsewhere.
- **Three slug normalizers for authors, none shared.** `NewBookController::handleAuthors` strips non-alphanumerics and joins first+last with `-`, no length cap. This differs from `BookController::handleAuthors` (no strip) and `AuthorController::getOrSetToBeCreatedAuthorsByName` (strips, capped at 30 chars). Same author entered through different flows can dedupe inconsistently. See `authors.md` for the full list.
- **`handleReadInstances` silently re-parents reads to `versions[0]`.** If a `read_instance` in the payload has no `version_id`, the controller assigns it `versions[0]->version_id` regardless of which version the user actually checked off. The client today always pushes the read-instance into the most recently added version (see `addReadInstanceToNewBookVersion`), and that version is also the only one in `currentBookData.versions` at submit time, so this happens to work — but the `// FOR NOW!!!` comment marks it as load-bearing. Adding multi-version creates without fixing this will misroute read history.
- **`handleVersions` accepts `version_id` to mean "use existing".** If a payload version carries a `version_id`, the controller does `Version::find($id)` and skips creation. Only the `setBookFromExisting` path populates `version_id` today (and it's not currently submittable from the new-book flow), so this branch is exercised by the existing-book flows that share the store rather than by `/new-book/`. Treat it as a contract: anything carrying `version_id` will not be re-created.
- **`completeBookCreation` always returns HTTP 200, even on failure.** The catch block returns `{ success: false, message, trace }` with no `response()->setStatusCode`. Callers must inspect `data.success`; relying on HTTP status will silently treat failures as successes. The same response also leaks the full PHP stack trace via `$e->getTrace()`.
- **The submit handler doesn't surface errors.** `NewBookSubmitControls::submitBook` only navigates on `res.data.success === true`; on failure it does nothing — no toast, no console error, no retry. The form just sits there.
- **`NewVersionsInput` has dead `existingBook` / `bookId` props.** When `existingBook` is true the component routes through `BooksStore.addVersionToBook` instead of the new-book flow. Nothing in the codebase passes these props — the existing-version path uses `AddVersionView`, which renders its own form. Either delete the branch or wire it up; today it's confusion bait.
- **`is_read` on a version is a step branch, not a persisted field.** It only decides whether the next step is `NewReadInstanceInput` or `NewBookSubmitControls`; the version row itself has no `is_read` column.
- **Existing read-instances are loaded on the duplicate-title path.** `setBookFromExisting` populates `read_instances` from the existing book payload (already user-scoped by the controller). Submitting from there does not re-create them — `addReadInstanceToNewBookVersion` only fires on the new-book branch — but anything that *did* call `submitNewBook` with prefilled `read_instances` would re-insert them as duplicates. The current UI doesn't do that, but it's a sharp edge if someone wires a "merge changes back into existing book" flow off this store.

## Usage notes

### Step 1 — title check

`POST /api/create-book/title` body `{ title }`. Response:

```
// new title
{ exists: false, book: { title, slug } }

// existing match (slug collision after normalization)
{ exists: true, book: { book_id, title, slug, authors, genres, versions: [...with format and readInstances], … } }
```

The SPA stores `book.slug` for round-trip submission and either advances to `NewAuthorsInput` (new) or `NewBookVersionConfirmation` (existing).

### Step 2 — submit

`POST /api/create-book` body `{ bookData: { book, authors, genres, versions, read_instances } }`. Each sub-array shape:

- `authors[i]`: `{ first_name, last_name }` (slug computed server-side).
- `genres[i]`: `{ name }` (matched / created by `name`, not slug).
- `versions[i]`: `{ format: { format_id }, page_count, audio_runtime, nickname }` for new versions, or `{ version_id }` to reuse an existing one.
- `read_instances[i]`: `{ date_read, rating }`. `version_id` is optional and falls back to `versions[0]` if missing (see gotchas).

Response on success: `{ success: true, book, authors, genres, versions, read_instances }`. Response on failure: `{ success: false, message, trace }` — still HTTP 200.

The SPA navigates to `{ name: 'books.show', params: { slug: data.book.slug } }` on success.

### Existing-book branch

When the title check returns `exists: true`, `NewBookVersionConfirmation` offers two choices:

- **Create New Version** — `<router-link>` to `books.add-version` (`AddVersionView`), which is a separate flow against `POST /api/versions`. The new-book store is not used past this point.
- **Create New Book** — calls `resetToAuthors`, which keeps the title, assigns a random client-side slug, and re-enters the standard new-book pipeline. The user ends up with a second book sharing the title (slug differentiated by server-side `generateUniqueSlug`).

### Cancel / abort

`NewBookSubmitControls` "Cancel" calls `resetStore()`, which clears `currentBookData` and resets the step to `NewBookTitleInput`. Navigating away mid-flow also discards state — `NewBookView.created()` calls `resetStore` on entry.

## Related

- Plan file: `/feature-plans/new-book-creation.md` — known limitations and future improvements.
- `/documentation/books.md` — the `BookController::store` path used by `/add-books`, the `Book` / `Version` / `ReadInstance` shapes this flow writes to, and the orphan-prune behavior on book delete.
- `/documentation/authors.md`, `/documentation/genres.md`, `/documentation/formats.md` — taxonomy attached during creation. Slug normalizer drift is documented in `authors.md`.
- `/documentation/read-history.md` (planned) — covers the standalone "add read history" path that reuses `NewBookStore`.
