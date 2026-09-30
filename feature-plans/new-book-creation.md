---
path: /feature-plans/
status: living
---

# New book creation

Tracks rough edges and follow-up work for the new-book page. Descriptive content lives in `/documentation/new-book-creation.md`.

The seven-step wizard was replaced by a single form on 2026-09-29 (CHANGELOG 0.1.23). That retired this file's step-machine items — no back button, stringly-typed step names, fixed step order, the progress panel reading stale data, the misleading duplicate-title screen, focus lost between steps, and the silent submit failure. The items below are what survived it, plus what the new shape introduced.

Many limitations here cross-cut taxonomy plans (authors, genres, formats) and the books pipeline. Where an item is owned elsewhere, this file links instead of restating.

## Known limitations

### Authorization & ownership

- **No rate limiting.** `POST /create-book/title` and `POST /create-book` are unthrottled. A loop on either is the cheapest way for an authenticated user to spam taxonomy rows or fill the book table.

### Validation & request shape

- **A whitespace-only title is only caught client-side.** `validateDraft` trims before checking, but `CompleteBookCreationRequest` is `required|string|max:255` without a trim, so another caller can still send `'   '`. Trim in `prepareForValidation` (and everywhere else a name is slugified — same gap on authors). Genres no longer have this gap: `GenreService::normalize` runs on every ingest door.
- **`bookData.genres.*.name` is `nullable`, not `required`.** Deliberate — a blank genre row is a form artifact and `GenreService` drops it, matching the other two ingest doors. It does mean this endpoint will not tell a caller that a genre failed to parse; it just returns fewer genres. See `/documentation/genres.md`.
- **Empty arrays are accepted by the API.** `authors: []` or `versions: []` is processed happily — a book with no authors or no copies, which then breaks the version-bound read fallback. The page can't send either (it validates an author and a format), but the backend doesn't enforce it.
- **Genres are no longer required.** The wizard refused to advance without one; the form doesn't ask, because the API, the edit form and the importer never did. If an ungenred book turns out to be a problem, the rule belongs in the request, not only the form.

### Data integrity

- **`BookCreator::slugFor` is not race-safe on its own,** but `books.slug` is uniquely indexed at the DB level, so a losing race surfaces as a `QueryException` and rolls back inside the transaction rather than silently duplicating. Tracked alongside the broader slug-uniqueness work in `/feature-plans/books.md`.
- **`handleReadInstances` re-parents version-less reads to `versions[0]`.** The page always sends exactly one copy with the read, so this is correct today — and is the only thing standing between a multi-copy create and misrouted read history. See Future improvements item 3.
- **The create endpoint isn't idempotent.** The disabled-while-saving button stops a double click, but a network failure after the server committed leaves the user looking at an error for a book that exists; retrying makes a second one under `title-surname`. An idempotency key on the request would close it.

### Frontend & UX

- **No persistence across reloads.** The draft is a component `ref`; a reload or navigating away loses it. Much less to lose than the wizard (one screen, not seven), but still Future improvements item 1.
- **One copy per create.** The form records the copy in hand; a second copy (the audiobook as well as the paperback) is added from the book page afterwards. Deliberate for now — it's also what keeps the read fallback above correct.
- **No shelf position.** `shelf_ordinal` stays null; the copy goes on the shelf with no position, and the shelf's default sort puts it last. Nothing but the CSV and the shelve endpoint's optional parameter sets position anyway — `/feature-plans/locations.md` item 3.
- **The title check is blur-only.** It fires on `change`, so a user who types a title and submits with Enter without leaving the field never sees the matches. The server would accept the create either way (a clash is filed under the surname), but the heads-up is missed. Checking on a debounced `input` would catch it.
- **Location counts go stale after a shelved create.** `LocationsStore.allLocations` isn't refetched; `LocationsView` force-refetches on mount, so the one screen that shows counts is right, as with the book page's moves (`/feature-plans/locations.md` → Known limitations).
- **`NewBookView` has no component test.** Its logic lives in `utils/newBookForm.js`, which is covered, but the wiring (validation timing, the stale-response guard on the title check, the disabled submit) isn't — the repo has no DOM test environment yet (`/feature-plans/frontend-tests.md`).

### Extensibility

- **The store name lies.** `NewBookStore` no longer serves the new-book page at all; it's the existing-book store for `AddReadHistoryView` and `UpdateBookReadInstance`. See Future improvements item 2.
- **`POST /books` has no caller.** `/add-books` (its only SPA caller) was deleted 2026-09-29, but the endpoint, `StoreBookRequest` and their tests remain, because its ingest tests pin that every door agrees on authors, genres and length fields — so it still costs upkeep while serving nothing. Future improvements item 4.

## Future improvements

In rough priority order.

1. **Persist the draft to `localStorage`.** Save the draft on change, offer to restore it on arrival, clear it on success. Wrap every access in try/catch.
2. **Rename `NewBookStore` to something like `CurrentBookStore`** (or fold it into `BooksStore`). Two callers; see `/feature-plans/read-history.md` for the coupling it carries.
3. **Fix the read-instance version routing in `handleReadInstances`.** Nest reads under their copy in the payload (`versions[i].read_instances`) so a read is attached to the copy it belongs to by construction, and drop the `versions[0]` fallback. Prerequisite for multi-copy creates.
4. **Delete `POST /books`** — `/feature-plans/books.md` item 2. With `/add-books` gone, `POST /create-book` owns creation. Deleting `BookController::store` and `StoreBookRequest` means moving the ingest tests that go through it (`AuthorIngestTest`, `GenreIngestTest`, `BookWriteValidationTest`, `VersionLengthFieldsTest`, `BooksCrudTest`) onto `/create-book` where they pin something still reachable, and keeping `prepareVersions`, which `update` shares.
5. **Pre-empt the same-title question when authors settle it.** Once an author is entered, re-run the check with authors so the server can apply `BookMatcher::find` and say "this *is* Plath's *Ariel* — add a copy?" rather than listing every same-title book.
6. **Add an "import from external source" affordance.** Every field is hand-typed. A "look up by ISBN" that pre-fills the draft (Open Library, Google Books) fits the single form naturally — it's one more field above Title that populates the others. Coordinate with `/feature-plans/enrichment-microservices.md`.
