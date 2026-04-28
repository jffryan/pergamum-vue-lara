---
path: /feature-plans/
status: living
---

# New book creation

Tracks rough edges and follow-up work for the multi-step new-book flow. Descriptive content lives in `/documentation/new-book-creation.md`.

Many limitations here cross-cut taxonomy plans (authors, genres, formats) and the books pipeline. Where an item is owned elsewhere, this file links instead of restating.

## Known limitations

### Authorization & ownership

- **No per-user ownership of created books.** `completeBookCreation` only requires `auth:sanctum`; the resulting `Book` has no `user_id` and is visible to every authenticated user. Tracked under the multi-tenant gap in `/feature-plans/books.md`.
- **No rate limiting.** `POST /create-book/title` and `POST /create-book` are unthrottled. A loop on either is the cheapest way for an authenticated user to spam taxonomy rows or fill the book table.

### Validation & request shape

- **No `FormRequest` classes.** Both controller methods reach into `$request['title']` and `$request['bookData']` directly; missing keys throw undefined-index 500s. `bookData['book']`, `bookData['authors']`, `bookData['genres']`, `bookData['versions']`, `bookData['read_instances']` are all required-by-implication and unvalidated.
- **No length / character validation on title.** Whitespace-only or empty titles slug to empty strings (the `Str::limit(50)` is the only cap). Frontend `validateString` blocks empty submissions but the backend doesn't enforce it.
- **`audio_runtime` validation is broken.** `NewVersionsInput::validateVersion` checks `format?.name === "audio"`; the actual format name is `"Audiobook"`. Audiobook submissions never validate runtime presence and can persist with `null`.
- **Author last-name is the only required field client-side, fully optional server-side.** `validateString` runs on `last_name` only. The backend accepts an entirely empty author and computes `slug = ""` for it, which then becomes a real `Author` row via `firstOrCreate`.
- **Genre input has no shape validation server-side.** `handleGenres` calls `Genre::firstOrCreate(['name' => $genre['name']])`. Empty / whitespace / duplicate names within one request all create separate rows.

### Data integrity

- **`generateUniqueSlug` is not race-safe.** It does `LIKE 'base%'`, regexes the highest `-N` suffix, and returns `base-N+1`. Two concurrent inserts compute the same suffix and race; `books.slug` has no unique index to backstop. Tracked alongside the broader slug-uniqueness work in `/feature-plans/books.md`.
- **Slug normalization for authors is duplicated three ways.** `NewBookController::handleAuthors`, `BookController::handleAuthors`, and `AuthorController::getOrSetToBeCreatedAuthorsByName` each have their own rule. See `/feature-plans/authors.md` for the full list.
- **`handleReadInstances` re-parents version-less reads to `versions[0]`.** Marked with a `// FOR NOW!!!` comment in the controller. Today the SPA always submits exactly one version with the read attached, so this works — but any multi-version create or any client that omits `version_id` will misroute reads silently.
- **`read_instances` are pulled twice from existing books.** When `createOrGetBookByTitle` matches an existing book, the response includes the user's `readInstances` for each version; `setBookFromExisting` copies them onto `currentBookData.read_instances`. Nothing in the new-book flow today re-submits those, but if any future store action calls `submitNewBook` from the existing-book branch, the controller will insert them as duplicate read history.
- **Empty arrays are accepted silently.** A request with `authors: []`, `genres: []`, or `versions: []` is happily processed — the resulting book has no taxonomy and no versions, which then breaks the version-bound read-instance fallback. The frontend gates each step but the backend doesn't enforce it.

### Error handling

- **Failures return HTTP 200 with `success: false`.** `completeBookCreation`'s catch block sets no status code. Any caller checking HTTP status will treat failures as successes.
- **Stack traces are leaked to the client.** The catch returns `'trace' => $e->getTrace()` in the JSON response. This is a security and noise problem; production should never see this.
- **The submit UI swallows failures.** `NewBookSubmitControls::submitBook` only navigates on `success === true`. On failure: no toast, no console error, no retry — the user sees nothing happen. They have no way to recover their entered data short of cancelling and starting over.
- **Network failures crash the title step silently.** `beginBookCreation` doesn't catch `createOrGetBookByTitle`. If the request 500s or times out, the promise rejects, the title step's `await` throws, and the user sees nothing change. No error path.

### Performance & query shape

- **`createOrGetBookByTitle` eager-loads everything on a hit.** When a title matches, the response includes authors, genres, every version, every version's format, and every user-scoped read-instance. Fine today; the existing-book confirmation screen only needs the book's identity to route the user. Trim the eager-load to what the UI actually needs.
- **No debounce on title input.** A user typing a title and submitting will only fire once (form submit), but any future "show suggestions as you type" feature would need a debounce — title slugging on every keystroke would be wasteful.

### Frontend & UX

- **State machine has no back button.** Each store action only sets the *next* step; there's no `previousStep` / step history. A user who advances past authors and realizes they made a typo has to cancel the whole flow.
- **No persistence across reloads.** `NewBookView.created()` calls `resetStore`. A reload mid-flow loses everything entered — title, authors, genres, version. Local-storage persistence would cost very little.
- **The "duplicate title" screen is misleading.** "Create New Book" produces a book sharing the title with a server-disambiguated slug; "Create New Version" routes to a different flow entirely. The two buttons look symmetric but lead to very different outcomes. Worth either explaining inline or splitting into different screens.
- **`NewBookProgressForm` reads stale data on the existing-book branch.** It binds to `currentBookData.versions[i].format.name`, which is fine for new books but for existing-book entries shows whatever the loaded book's versions had. The progress form was built for the new-book path and incidentally renders for both.
- **No keyboard navigation between steps.** Each step has its own form; submitting one creates a new component tree, which loses focus. Power users adding a stack of books pay the click cost on every transition.
- **`NewVersionsInput::existingBook` / `bookId` props are dead.** Not passed by anything in the codebase. Either route `AddVersionView` through this component (the apparent original intent) or delete the prop branch.
- **Version `is_read` toggle persists nothing.** The toggle only steers the next step (read-instance form vs. submit). If the user toggles it on and then navigates away, no state of "the user said they read it" is captured anywhere.

### Extensibility

- **No tests for the create flow end-to-end.** `NewBookStore.test.js` covers store transitions but there's no Feature test for `completeBookCreation` — the transactional create, the slug regeneration, the author/genre `firstOrCreate` paths, and the read-instance fallback are all uncovered.
- **The store name lies.** `NewBookStore` is also "the current-book-being-mutated store" used by `AddReadHistoryView`, `AddVersionView`, and `UpdateBookReadInstance`. Anyone reading the name expects it to scope to the create flow only. Renaming requires touching those callers.
- **Step transitions are stringly-typed.** `setStep([...])` takes component name strings that `NewBookView` resolves through its locally registered components. Typos compile fine, render nothing, and emit no warning. A small registry mapping step keys to components would catch this and document the state machine in one place.
- **No pluggable step ordering.** The flow is hardcoded title → authors → genres → versions → (read?) → submit. Reordering or skipping a step (e.g., a "quick add" without genres) requires editing both the store and the component dispatch in `NewBookView`. A first-class step machine would let the order be data.

## Future improvements

In rough priority order.

1. **Add Feature tests** for `createOrGetBookByTitle` (hit and miss), `completeBookCreation` (happy path, transaction rollback on each `handle*` failure, the version-less read-instance fallback), and `generateUniqueSlug`. Necessary before any of the structural cleanup below.
2. **Introduce `FormRequest` classes** — `CreateBookTitleRequest` and `CompleteBookCreationRequest` — with explicit rules for title, author shape, genre shape, version shape (including conditional `audio_runtime` required-when-format-is-Audiobook), and read-instance shape. Replaces the current `$request['…']` array reaches.
3. **Stop returning HTTP 200 on failure and stop leaking traces.** Set `response()->json([...], 422)` (or 500 for unexpected) and drop the `trace` field. Update `NewBookSubmitControls` to surface a toast / inline error when `success: false`.
4. **Fix `NewVersionsInput` audiobook validation** — match against `format.name === "Audiobook"` (or, better, check `format.format_id` against the audio format from `ConfigStore`). Add a Vitest spec.
5. **Extract a single slug helper** for books and authors — companion to items in `/feature-plans/books.md` and `/feature-plans/authors.md`. Replace the inline `Str::of(...)->lower()->replaceMatches(...)` in `createOrGetBookByTitle`, `createBook`, and `handleAuthors` with calls into it.
6. **Make `books.slug` and `authors.slug` non-nullable + unique-indexed.** The `generateUniqueSlug` race window closes when the DB enforces uniqueness; current behavior becomes a `SQLSTATE` rollback inside the existing transaction (acceptable, since the whole create is wrapped). Backfill before adding the constraints.
7. **Trim the `createOrGetBookByTitle` hit response.** The duplicate-title screen needs only `book_id`, `title`, `slug`. Drop the eager-load of authors/genres/versions/format/readInstances, or make it opt-in via a query param.
8. **Persist in-progress new-book state to `localStorage`.** Hydrate `currentBookData` and `currentStep` on `NewBookView.created()` if a draft exists, prompt the user to resume or discard. Cheap UX win.
9. **Add a "back" affordance to the state machine.** Maintain a step-history stack in `NewBookStore` and add a `goBack()` action; render a Back button in `NewBookProgressForm` (or in each step component).
10. **Replace stringly-typed step transitions with a registry.** A `steps.js` map of `{ key: Component }` consumed by both `setStep` and `NewBookView`'s component resolution. Typos become import errors, the state machine becomes greppable.
11. **Rename `NewBookStore` to `BookEditorStore` (or split it).** It's used for create *and* for adding versions / read instances to existing books. Either rename to reflect that, or split into a pure-create store + a current-book store consumed by `AddVersionView` / `AddReadHistoryView` / `UpdateBookReadInstance`. The split is cleaner but touches more callers.
12. **Fix the read-instance version-routing in `handleReadInstances`.** Drop the `versions[0]` fallback; require `version_id` on every read instance and validate it. Update the SPA to thread the chosen version through `addReadInstanceToNewBookVersion`. Removes the `// FOR NOW!!!`.
13. **Reword the duplicate-title confirmation.** Either make "Create New Book" much more explicit ("Two distinct books can share a title — create a separate record?") or drop the option entirely and force the user into "add version to existing" / "cancel".
14. **Delete or wire the dead `existingBook`/`bookId` props on `NewVersionsInput`.** If `AddVersionView` should use this component, route it. If not, remove the prop branch and the `BooksStore.addVersionToBook` call inside the submit handler.
15. **Delete `/add-books` (`AddBooksView`).** Nothing links to it; it's a parallel single-form code path that does the same job worse. Keeping it forces every reader to reverse-engineer which path is canonical. If the form is worth keeping for some reason, repurpose `BookCreateEditForm` for edit-only and rename it.
16. **Surface book-create errors with a toast / banner.** Wire a top-level notification slot so failed submits don't silently no-op.
17. **Add an "import from external source" branch.** Today every field is hand-typed. Even a thin "look up by ISBN" call (Open Library, Google Books) would dramatically speed up entry — most of the multi-step pain is data entry, not state machine complexity. Coordinate with `/feature-plans/enrichment-microservices.md`.
