---
path: /feature-plans/
status: living
---

# Read history

Tracks rough edges and follow-up work for the post-create read-history flows (`/books/:slug/add-read-history` and `/completed`). Descriptive content lives in `/documentation/read-history.md`. The `ReadInstance` model itself is owned by `/feature-plans/books.md`; cross-cutting items link there rather than restate.

## Known limitations

### Authorization & ownership

- **No policy on read instances.** `addReadInstance` stamps `user_id` from the session, so a user cannot write a read against someone else's history. Since the catalog is shared on purpose (see `/documentation/books.md`), passing an arbitrary `book_id` / `version_id` is not a leak — but a `ReadInstancePolicy` is still needed the moment edit / delete endpoints land, because those target rows that *do* belong to someone.
- **No edit or delete endpoints for read instances.** There's `POST /add-read-instance` but no `PATCH /read-instances/{id}` or `DELETE /read-instances/{id}`. A misclicked rating, a wrong date, or a duplicate read entered twice cannot be corrected through the UI — only by editing the row in MySQL. The `ReadInstance` PK is `read_instance_id`; nothing in the SPA exposes it beyond the book edit form.

### Validation & request shape

- **`getBooksByYear($year)` accepts any string.** Eloquent binds the parameter so it's safe, but `/api/completed/abc` returns `[]` instead of `422` / `404`. Add an `int` typehint or a `Rule::numeric` + reasonable range.

### Data integrity

- **Reads without a date never appear in year-browse.** `whereYear('date_read', …)` filters out nulls. A book with only undated reads is "completed" on the detail page but invisible at `/completed`. Either render an "undated" tab, or reflect undated reads under the year they were *created* (`created_at`).
- **Optimistic store update before the API call.** `UpdateBookReadInstance` mutates `NewBookStore` and `BooksStore.allBooks[i]` *before* awaiting the axios POST. On failure the in-memory state diverges silently; the user sees a phantom read until reload.
- **`BooksStore.allBooks[bookIndex] = NewBookStore.currentBookData` couples store shapes.** The component overwrites a `BooksStore` element wholesale with whatever `NewBookStore.currentBookData` is. Any divergence in shape between the two breaks list rendering for that book.

### Performance & query shape

- **`whereYear` and `YEAR(date_read)` cannot use an index.** Both `getAvailableYears` and `getCompletedItemsForYear` will full-scan `read_instances` at scale. Switch to range queries (`date_read BETWEEN '$year-01-01' AND '$year-12-31'`) and add a `(user_id, date_read)` composite index.
- **`getCompletedItemsForYear` sorts in PHP.** Hydrates the entire matching set, transforms each book in PHP, then `->sortBy(…)` in memory. Push the sort to SQL (order books by their min `date_read` in the year, joined or via subquery).
- **Triple year-filter inside `getCompletedItemsForYear`.** The same `whereYear + user_id` predicate runs in `whereHas`, then again on `versions.readInstances`, then again on the direct `readInstances` relation. Eloquent ends up issuing three near-identical filtered fetches. A single user-scoped `whereYear` on a join would do.
- **No caching on `/completed`.** `CompletedView` refetches `loggedYears` on every mount and `getBooksByYear` on every tab click — even when re-clicking a tab the user already opened.

### Error handling

- **`UpdateBookReadInstance` fails silently.** On non-200 it `console.log("ERROR: ", res)` and `return`; no toast, no inline error, no rollback of the optimistic state. The user sees a successful-looking submission that did nothing.
- **`addReadInstance` doesn't catch.** A malformed payload is a 422 and the insert is transactional, but a DB exception still becomes a 500 with whatever debug payload Laravel surfaces.
- **`AddReadHistoryView` mounted hook crashes when the slug 404s.** It logs the error but then unconditionally accesses `this.currentBook.versions.length`, which throws `Cannot read properties of undefined`.

### Frontend & UX

- **A picked rating can't be cleared.** The "Select a rating" placeholder in `UpdateBookReadInstance` is `disabled`, so leaving the dropdown untouched records no rating, but once a value is chosen there is no way back to none short of reloading. The options also start at `1`, so the `0.5` that `App\Rules\Rating` accepts can't be entered here.
- **No way to view or edit existing read instances from a book page.** They're listed (in some surfaces) but not editable. To fix a typo'd date the user has to delete-and-recreate, which they also can't do through the UI.
- **Undated reads have no UX disclosure.** The form labels date as "(optional)" but doesn't explain what happens to undated reads (invisible in `/completed`, shown without a date on the book page).
- **`/completed` defaults to the most recent year, no permalink.** No `?year=2024` URL state — sharing or bookmarking a specific year doesn't work. Browser back doesn't restore the previous tab.
- **No empty state on `/completed`.** A user with zero reads sees an empty `<ul>` of tabs and an empty `BookshelfTable`. No "log your first read" CTA.
- **`BookshelfTable` rendering of year-browse data has subtle issues.** Books with multiple reads in the year show all reads as separate-looking rows in some configurations because `versions.readInstances` and `readInstances` are both populated and the table iterates one of them.
- **Direct `axios` import in `UpdateBookReadInstance`.** Violates the layering rule from `CLAUDE.md` (`views → services/stores → api → axios`). Add a `createReadInstance` wrapper to `api/BookController.js` and route through it.
- **`BookView::readHistory` returns a string on one path and an array on the other.** `if (!this.bookHasBeenCompleted) return "";`, otherwise an array of formatted read instances. It renders correctly today only by luck: the `v-for` consuming it sits behind `v-if="bookHasBeenCompleted"`, the same condition that produces the `""`, so the string path is never iterated. Vue 3's `v-for` iterates a string by character, so moving or loosening that guard would render one `<div>` per character. Its own siblings in the same file (`authorRelatedBooks`, `listsContainingBook`) correctly `return []`, so this is an outlier rather than a house style. Same class of defect as the `"Unknown"` fallbacks logged under `/feature-plans/books.md` Frontend — a fallback whose type doesn't match what the template does with it.

### Extensibility

- **The year-browse aggregation is untested.** `getAvailableYears` and `getCompletedItemsForYear` have no coverage of year-filter accuracy, sort order, multi-year books or undated reads. (`addReadInstance` is covered by `AddReadInstanceTest` and `BookWriteValidationTest`.)
- **No store for year-browse state.** `CompletedView` keeps `loggedYears` / `activeYear` / `activeBooks` in `data()`. A future "include in stats", "export year as CSV", or "compare two years" feature has nowhere to hang.
- **MySQL-specific `YEAR()` and `whereYear`.** Locks the year-browse to MySQL. If the project ever moves to Postgres / SQLite the queries break.

## Future improvements

In rough priority order.

1. **Add `createReadInstance` to `api/BookController.js`** and route `UpdateBookReadInstance` through it. Removes the direct axios import and the layering violation.
2. **Switch year filtering to range queries.** Replace `whereYear('date_read', $year)` with `whereBetween('date_read', ["$year-01-01", "$year-12-31"])` and add a `(user_id, date_read)` index migration. Same change in `getAvailableYears` (or rewrite it as `DISTINCT EXTRACT(YEAR FROM date_read)` portably).
3. **Push `getCompletedItemsForYear` sorting into SQL.** Order by `MIN(read_instances.date_read)` per book at the query level instead of `->sortBy` in PHP. Drops the in-memory hydration cost.
4. **Fix the silent failure path in `UpdateBookReadInstance`.** On non-200, roll back the optimistic store update and surface a toast or inline error. Add the same to `addReadInstance` controller — return a structured error, not a 500.
5. **Make rating truly optional.** Add a "No rating" option that sends `null`; guard the mutator to leave `null` as `null` rather than coercing to 0.
6. **Build edit / delete endpoints for read instances.** `PATCH /read-instances/{id}` and `DELETE /read-instances/{id}`, both authorized via a new `ReadInstancePolicy` that checks `user_id`. Surface edit / delete affordances on the book detail page's read-history list.
7. **URL-state the year tab** in `CompletedView` (`/completed?year=2024`). Restore on mount; back/forward navigates between tabs.
8. **Add an empty state to `/completed`** with a CTA to log a read or visit the library.
9. **Surface undated reads.** Either an "Undated" tab in `CompletedView` or fold them under their `created_at` year — pick the user-facing semantics that's least confusing and document it.
10. **Cache `loggedYears` and recently fetched year payloads** in a Pinia `ReadHistoryStore`. Cheap UX win once `CompletedView` has a back button or a dashboard surface that also reads it.
11. **Backfill `read_instances.book_id` consistency check.** New writes are blocked by the model-side `saving` listener, but pre-existing mismatched rows (if any predate the validator) won't surface until something tries to re-save them. Run a one-shot audit query (`select read_instances.* from read_instances join versions using (version_id) where read_instances.book_id <> versions.book_id`) and reconcile.
12. **Coordinate with `/feature-plans/statistics-widgets.md`.** Aggregations across `ReadInstance` (totals, average rating per year, fastest read, etc.) live in the stats doc but share the same MySQL-specific year functions and the same user-scoping convention. Whatever index strategy lands here should be reused there.
