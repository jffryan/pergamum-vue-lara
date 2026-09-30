---
path: /documentation/
status: living
---

# New book creation

## Scope

Covers the "New book" page at `/new-book/` (`NewBookView` + `utils/newBookForm.js` + `NewBookController`) — the SPA's only way to create a book since the legacy `/add-books` form was deleted (2026-09-29). Its copy fields are the shared `components/books/CopyFields.vue` + `utils/copyForm.js`, which the add-a-copy page (`/books/:slug/new-version` → `AddVersionView` → `POST /versions`) also uses; that page is described in `books.md`. The "add read history" path (`/books/:slug/add-read-history` → `AddReadHistoryView`) is in `read-history.md`.

## Summary

One page, one book, the copy in hand. The form has three sections — **Book** (title, authors, genres), **Your copy** (format, the length that format carries, shelf, nickname) and **Reading** (an "I've read this copy" box that opens a finished date and a rating) — and one submit, which posts the whole graph to `POST /api/create-book` and lands on the new book's page. Leaving the title field asks the server whether books with that title already exist; if so, they're listed under the field with an "Add a copy to this one" link each, and the user can carry on regardless.

It replaced (2026-09-29) a seven-step wizard — title → same-title confirmation → authors → genres → copy → read → review — whose steps were separate forms swapped in by `NewBookStore`. The wizard had no back button, lost everything on a reload, swallowed submit failures, and couldn't set a shelf; see the changelog for 0.1.23.

## How it's wired

### Backend

- **Routes** (`routes/api.php`, all under `auth:sanctum`):
  - `POST /create-book/title` → `NewBookController::createOrGetBookByTitle` — slug-and-lookup via `BookMatcher::sameTitle`. Returns `{ exists: bool, book: { title, slug }, matches: [{ book_id, title, slug, authors }] }`. Read-only: a miss creates nothing. The page uses only `matches`.
  - `POST /create-book` → `NewBookController::completeBookCreation` — validates through `CompleteBookCreationRequest`, then persists the book, authors, copies (shelving any that name a location), reads and genres inside one `DB::transaction`.
- **Controller**: `NewBookController` delegates the book row to `BookCreator`, authors to `AuthorService::attachToBook`, genres to `GenreService::attachByName`, and shelving to `LocationService::shelveVersion`. Copy and read creation are still inline `handle*` helpers.
- **Request**: `CompleteBookCreationRequest` — author-name rules shared with the other doors (`ValidatesAuthorNames`), read dates normalized to `Y-m-d` (`NormalizesReadDates`), ratings through `App\Rules\Rating`. `versions()` splits rows into `existing` ids and `new` rows of `{ attributes, location_id }`, with the length fields already reduced to what the format carries.
- **Models / tables**: creates `Book`, attaches `Author` and `Genre` rows, creates `Version` rows (and sets `location_id` on them via the service), creates `ReadInstance` rows scoped to `auth()->id()`. No migrations specific to this flow.
- **Authorization**: none beyond `auth:sanctum` — the catalog is shared by design.

### Frontend

- **View**: `views/NewBookView.vue` (`<script setup>`) holds the draft in a local `ref` — no store — and does layout and wiring only. Styled like the book page: a `← Library` link, quiet `border-b` section headings, muted labels, text-link secondary actions, `AlertBox` for a failed submit.
- **Logic**: `utils/newBookForm.js`, pure and unit-tested (`tests/utils/newBookForm.test.js`):
  - `emptyDraft()` — `{ title, authors: [{first_name, last_name}], genres, copy: { format_id, page_count, audio_runtime, nickname, location_id }, read: { has_read, date_read, rating } }`.
  - `validateDraft(draft, formats)` → `{ field: message }`, empty when submittable. Mirrors the request: a non-blank title, at least one named author, a format, and the length field that format expects. Genres, shelf, nickname, date and rating are optional.
  - `toPayload(draft, formats)` → the `bookData` body. Blank author rows dropped, names trimmed, lengths the format doesn't carry sent as null, the read included only when the box is ticked.
  - `namedAuthors`, `canAddAuthor`, `matchAuthorLine`, `submitErrorMessage`, `RATINGS` (0.5–5 in half steps, the server's scale).
- **Copy fields**: `components/books/CopyFields.vue` renders format, length, shelf and nickname as a controlled component (`copy` in, `update:copy` out); `utils/copyForm.js` (`emptyCopy`, `validateCopy`, `copyPayload`, `saveErrorMessage`) holds their rules and version-row payload. `newBookForm.js` composes them into the book-level draft. The add-a-copy page uses the same pair, so a copy is entered and validated identically through both doors.
- **Shelf select**: options come from `LocationsStore.shelfOptions` — the tree's leaves labelled with their ancestor path (`Office / O1 / O1S5`), sorted by it. `ShelfPicker` on the book page reads the same getter, so a shelf is offered and named identically in both places. The null option is "— Unshelved —".
- **Genres**: `components/genres/GenreTagInput.vue`, shared with `EditBookView`; see `genres.md`.
- **Formats**: `ConfigStore.checkForFormats()` on mount; which length field shows is read from the format's capability flags through `utils/formats.js`, never from its name.
- **API layer**: `api/BookController.js` — `createOrGetBookByTitle(title)` and `submitNewBook(bookData)`.
- **Route**: `router/book-routes.js` — `/new-book/` (named `books.new`), linked from `SidebarNav`.

## Non-obvious decisions and gotchas

- **The shelf is set through `LocationService::shelveVersion`, not a mass-assigned column.** The locations work deliberately kept placement to one write path so form doors couldn't drift from the picker. This door honours that: the request keeps `location_id` out of the `Version::create()` attributes, and the controller calls the same service method the book page's picker does, inside the create transaction. A copy created on a shelf is indistinguishable from one created and then shelved. `shelf_ordinal` is left null — position on the shelf is still the CSV's and the shelve endpoint's business.
- **An existing copy can't be given a location here.** A version row carrying `version_id` and `location_id` is a 422 (`prohibits`) rather than a silently ignored field — moving a copy is `PATCH /versions/{version}/location`. The page never sends existing rows; the rule is for other callers.
- **The same-title check is advice, not a gate.** Title alone is not book identity (Plath's *Ariel* and Rodó's), and at the title field there are no authors yet for `BookMatcher::find` to decide with. So matches are shown with their authors and an "Add a copy to this one" link, and submitting anyway creates a second book filed under its author's surname by `BookCreator`. The check fires on the title's `change` event (blur), skips a title it has already checked, drops a response for a title that's since changed, and on failure logs and carries on — it must never block creation.
- **Validation messages appear after the first submit attempt,** then track every edit. An empty form on arrival shows no errors.
- **The submit button disables while saving.** The create endpoint is not idempotent — a retried `POST /create-book` makes a second book under a disambiguated slug — so a double click must not send twice. A failure re-enables it and shows the first field error of a 422, or a generic line otherwise.
- **A read names no copy.** The copy doesn't exist until the request runs, so `read_instances[0]` goes without a `version_id` and `handleReadInstances` files it against the payload's first — here, only — copy. The form only ever sends one copy, which is what keeps that fallback correct; see the plan.
- **An unknown finish date is a read, not an error.** "Finished (blank if unknown)" sends `date_read: null`, the same convention as discarding a copy with no date.
- **`NewBookStore` is no longer part of this flow.** What remains of it (`currentBookData`, `setBookFromExisting`, `addReadInstanceToExistingBookVersion`) serves `AddReadHistoryView` and `UpdateBookReadInstance`. The name is now purely historical.
- **`completeBookCreation` answers 200 with `success: true`, or 500 with `success: false`.** Validation failures are 422s from the request before the transaction opens. The page relies on axios rejecting non-2xx, not on `success`.

## Usage notes

### Title check

`POST /api/create-book/title` body `{ title }`:

```
// new title
{ exists: false, book: { title, slug }, matches: [] }

// same-title books exist
{ exists: true, book: { title, slug }, matches: [{ book_id, title, slug, authors: [{ author_id, first_name, last_name, slug, … }] }, …] }
```

### Submit

`POST /api/create-book` body `{ bookData: { book, authors, genres, versions, read_instances } }`:

- `book`: `{ title }` (a client `slug` is accepted and ignored — `BookCreator` settles the real one).
- `authors[i]`: `{ first_name, last_name }`; at least one of the two per row.
- `genres[i]`: `{ name }` (matched / created by name; blank names dropped).
- `versions[i]`: `{ format: { format_id }, page_count, audio_runtime, nickname, location_id }` for a new copy — `location_id` optional, null for unshelved, any real location id — or `{ version_id }` to reuse an existing one (no `location_id` allowed).
- `read_instances[i]`: `{ date_read, rating }`, `version_id` optional (falls back to `versions[0]`).

Success: `{ success: true, book, authors, genres, versions, read_instances }`; the page routes to `{ name: 'books.show', params: { slug: data.book.slug } }`.

## Related

- Plan file: `/feature-plans/new-book-creation.md` — known limitations and future improvements.
- `/documentation/books.md` — the add-a-copy page, the deleted `POST /books`, and the `Book` / `Version` / `ReadInstance` shapes this flow writes.
- `/documentation/locations.md` — `LocationService::shelveVersion`, the shelf tree, and the virtual Unshelved location a copy with no shelf lands in.
- `/documentation/authors.md`, `/documentation/genres.md`, `/documentation/formats.md` — taxonomy attached during creation.
- `/documentation/read-history.md` — the standalone "add read history" path.
