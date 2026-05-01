---
path: /feature-plans/
status: living
---

# Read history

Tracks rough edges and follow-up work for the post-create read-history flows (`/books/:slug/add-read-history` and `/completed`). Descriptive content lives in `/documentation/read-history.md`. The `ReadInstance` model itself is owned by `/feature-plans/books.md`; cross-cutting items link there rather than restate.

## Known limitations

### Authorization & ownership

- **No policy on read instances.** `addReadInstance` only requires `auth:sanctum`; the user_id is stamped from the session, but nothing prevents a user from passing an arbitrary `book_id` / `version_id` they don't otherwise interact with. Today every book is global, so this is moot — the moment book ownership exists (see `/feature-plans/books.md`), this becomes a leak.
- **No edit or delete endpoints for read instances.** There's `POST /add-read-instance` but no `PATCH /read-instances/{id}` or `DELETE /read-instances/{id}`. A misclicked rating, a wrong date, or a duplicate read entered twice cannot be corrected through the UI — only by editing the row in MySQL. The `ReadInstance` PK is `read_instances_id`; nothing in the SPA exposes it.

### Validation & request shape

- **No `FormRequest`.** `addReadInstance` reaches into `$request['readInstance']`, then `$request['readInstance']['book_id']`, etc. Missing keys throw undefined-index 500s.
- **Empty `rating` becomes `0`.** The frontend's `<select>` defaults to `""`; the mutator doubles `null !== '' === true` and `'' * 2` is `0` (PHP coerces). Explicit "no rating" should be `null`, not `0`. Either the form must send `null` for unselected, or the mutator must guard.
- **Date format isn't normalized server-side.** The SPA sends `MM/DD/YYYY` strings; the `read_instances.date_read` column type accepts MySQL's permissive parsing today, but a non-MySQL backend or a stricter mode would reject this. Normalize to `Y-m-d` before insert.
- **`getBooksByYear($year)` accepts any string.** Eloquent binds the parameter so it's safe, but `/api/completed/abc` returns `[]` instead of `422` / `404`. Add an `int` typehint or a `Rule::numeric` + reasonable range.

### Data integrity

- **Reads without a date never appear in year-browse.** `whereYear('date_read', …)` filters out nulls. A book with only undated reads is "completed" on the detail page but invisible at `/completed`. Either render an "undated" tab, or reflect undated reads under the year they were *created* (`created_at`).
- **`addReadInstance` writes two queries to set both FKs.** `$book->readInstances()->save(...)` performs an INSERT, then `$version->readInstances()->save(...)` performs an UPDATE. Cleaner: set `book_id` and `version_id` on the model directly and call `->save()` once.
- **Optimistic store update before the API call.** `UpdateBookReadInstance` mutates `NewBookStore` and `BooksStore.allBooks[i]` *before* awaiting the axios POST. On failure the in-memory state diverges silently; the user sees a phantom read until reload.
- **`BooksStore.allBooks[bookIndex] = NewBookStore.currentBookData` couples store shapes.** The component overwrites a `BooksStore` element wholesale with whatever `NewBookStore.currentBookData` is. Any divergence in shape between the two breaks list rendering for that book.

### Performance & query shape

- **`whereYear` and `YEAR(date_read)` cannot use an index.** Both `getAvailableYears` and `getCompletedItemsForYear` will full-scan `read_instances` at scale. Switch to range queries (`date_read BETWEEN '$year-01-01' AND '$year-12-31'`) and add a `(user_id, date_read)` composite index.
- **`getCompletedItemsForYear` sorts in PHP.** Hydrates the entire matching set, transforms each book in PHP, then `->sortBy(…)` in memory. Push the sort to SQL (order books by their min `date_read` in the year, joined or via subquery).
- **Triple year-filter inside `getCompletedItemsForYear`.** The same `whereYear + user_id` predicate runs in `whereHas`, then again on `versions.readInstances`, then again on the direct `readInstances` relation. Eloquent ends up issuing three near-identical filtered fetches. A single user-scoped `whereYear` on a join would do.
- **No caching on `/completed`.** `CompletedView` refetches `loggedYears` on every mount and `getBooksByYear` on every tab click — even when re-clicking a tab the user already opened.

### Error handling

- **`UpdateBookReadInstance` fails silently.** On non-200 it `console.log("ERROR: ", res)` and `return`; no toast, no inline error, no rollback of the optimistic state. The user sees a successful-looking submission that did nothing.
- **`addReadInstance` doesn't catch.** Any DB exception becomes a 500 with whatever debug payload Laravel decides to surface (the stack trace, in dev). No transaction wraps the two saves — a failure on the second leaves the row half-populated (book_id set, version_id missing).
- **Dead `if (!$book || !$version)` branch.** `findOrFail` already 404s, so the controller's manual not-found check never runs. Either remove `findOrFail` and add explicit handling, or remove the dead branch.
- **`AddReadHistoryView` mounted hook crashes when the slug 404s.** It logs the error but then unconditionally accesses `this.currentBook.versions.length`, which throws `Cannot read properties of undefined`.

### Frontend & UX

- **Multi-version books are unselectable.** The version cards in `AddReadHistoryView` render with selection styling but have no click handler — only the auto-selected single-version case works. Users with two-format books cannot record a read against either one through this surface.
- **No rating "none" option.** The select hides "Select a rating" when disabled, and there's no "no rating" choice. A user who genuinely has no rating to record can either leave the dropdown un-touched (sends `''` → stored as `0`) or pick `1` (which means 0.5 stars on display).
- **No way to view or edit existing read instances from a book page.** They're listed (in some surfaces) but not editable. To fix a typo'd date the user has to delete-and-recreate, which they also can't do through the UI.
- **Undated reads have no UX disclosure.** The form labels date as "(optional)" but doesn't explain what happens to undated reads (invisible in `/completed`, shown without a date on the book page).
- **`/completed` defaults to the most recent year, no permalink.** No `?year=2024` URL state — sharing or bookmarking a specific year doesn't work. Browser back doesn't restore the previous tab.
- **No empty state on `/completed`.** A user with zero reads sees an empty `<ul>` of tabs and an empty `BookshelfTable`. No "log your first read" CTA.
- **`BookshelfTable` rendering of year-browse data has subtle issues.** Books with multiple reads in the year show all reads as separate-looking rows in some configurations because `versions.readInstances` and `readInstances` are both populated and the table iterates one of them.
- **Direct `axios` import in `UpdateBookReadInstance`.** Violates the layering rule from `CLAUDE.md` (`views → services/stores → api → axios`). Add a `createReadInstance` wrapper to `api/BookController.js` and route through it.

### Extensibility

- **No tests for `addReadInstance`, `getAvailableYears`, or `getCompletedItemsForYear`.** None of the year-browse aggregation, the dual-FK save, the rating mutator, or the date-null behavior is covered.
- **No store for year-browse state.** `CompletedView` keeps `loggedYears` / `activeYear` / `activeBooks` in `data()`. A future "include in stats", "export year as CSV", or "compare two years" feature has nowhere to hang.
- **MySQL-specific `YEAR()` and `whereYear`.** Locks the year-browse to MySQL. If the project ever moves to Postgres / SQLite the queries break.
- **Read-history-related fields are spread across two store actions.** `addReadInstanceToNewBookVersion` (new-book flow, `NewBookStore`) and `addReadInstanceToExistingBookVersion` (post-create flow, same store). The split makes sense given the flow difference, but the store is named `NewBookStore` — see `/feature-plans/new-book-creation.md` for the rename.

## Future improvements

In rough priority order.

1. **Add Feature tests** for `POST /add-read-instance` (success, mismatched book/version, missing keys, null `date_read`, empty rating), `GET /completed/years`, and `GET /completed/{year}` (year filter accuracy, sort order, multi-year books, undated reads). Necessary before any of the structural cleanup below.
2. **Add `createReadInstance` to `api/BookController.js`** and route `UpdateBookReadInstance` through it. Removes the direct axios import and the layering violation.
3. **Introduce a `StoreReadInstanceRequest` `FormRequest`** with rules for `book_id` (exists), `version_id` (exists, belongs to book_id — surface as a 422 here rather than relying on the model-side `\DomainException` safety net), `date_read` (nullable date, accepts both `Y-m-d` and `m/d/Y`), `rating` (nullable, between 0.5 and 5 in 0.5 steps).
4. **Wrap `addReadInstance` in a transaction** and collapse the dual save into one (`new ReadInstance($data + ['user_id' => …])->save()` after setting both FKs). Removes the half-row failure window.
5. **Switch year filtering to range queries.** Replace `whereYear('date_read', $year)` with `whereBetween('date_read', ["$year-01-01", "$year-12-31"])` and add a `(user_id, date_read)` index migration. Same change in `getAvailableYears` (or rewrite it as `DISTINCT EXTRACT(YEAR FROM date_read)` portably).
6. **Push `getCompletedItemsForYear` sorting into SQL.** Order by `MIN(read_instances.date_read)` per book at the query level instead of `->sortBy` in PHP. Drops the in-memory hydration cost.
7. **Fix the silent failure path in `UpdateBookReadInstance`.** On non-200, roll back the optimistic store update and surface a toast or inline error. Add the same to `addReadInstance` controller — return a structured error, not a 500.
8. **Handle multi-version selection in `AddReadHistoryView`.** Add `@click="selectedVersion = version"` to each card. Show an inline "Select a version" hint when `selectedVersion` is null and the version count is > 1.
9. **Make rating truly optional.** Add a "No rating" option that sends `null`; guard the mutator to leave `null` as `null` rather than coercing to 0.
10. **Normalize `date_read` input to `Y-m-d` before submit.** Either client-side in `UpdateBookReadInstance` or server-side in the new `FormRequest`.
11. **Build edit / delete endpoints for read instances.** `PATCH /read-instances/{id}` and `DELETE /read-instances/{id}`, both authorized via a new `ReadInstancePolicy` that checks `user_id`. Surface edit / delete affordances on the book detail page's read-history list.
12. **URL-state the year tab** in `CompletedView` (`/completed?year=2024`). Restore on mount; back/forward navigates between tabs.
13. **Add an empty state to `/completed`** with a CTA to log a read or visit the library.
14. **Surface undated reads.** Either an "Undated" tab in `CompletedView` or fold them under their `created_at` year — pick the user-facing semantics that's least confusing and document it.
15. **Cache `loggedYears` and recently fetched year payloads** in a Pinia `ReadHistoryStore`. Cheap UX win once `CompletedView` has a back button or a dashboard surface that also reads it.
16. **Backfill `read_instances.book_id` consistency check.** New writes are blocked by the model-side `saving` listener, but pre-existing mismatched rows (if any predate the validator) won't surface until something tries to re-save them. Run a one-shot audit query (`select read_instances.* from read_instances join versions using (version_id) where read_instances.book_id <> versions.book_id`) and reconcile.
17. **Coordinate with `/feature-plans/statistics.md`.** Aggregations across `ReadInstance` (totals, average rating per year, fastest read, etc.) live in the stats doc but share the same MySQL-specific year functions and the same user-scoping convention. Whatever index strategy lands here should be reused there.
