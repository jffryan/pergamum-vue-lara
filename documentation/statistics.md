---
path: /documentation/
status: living
---

# Statistics

## Scope

Covers the server-side, user-wide statistics dashboard at `/statistics` (`StatisticsDashboard.vue` → `GET /api/statistics` → `StatisticsService::getUserStats`). **Does not** cover per-list statistics — those are computed client-side from the list show payload and are owned by `lists.md`. Also does not cover the year-browse "Completed" surface, which is read-history aggregation in `read-history.md`. `LibraryView.vue` is a paginated book list, not a stats surface, despite being mentioned in the same plan.

## Summary

A single endpoint (`GET /api/statistics`) returns a flat object of pre-computed numbers covering the catalog and the current user's reading: total catalog size, unique books read, reads-per-year, pages-per-year, % of catalog completed, and five most recently created books. The dashboard view consumes the payload directly and computes `totalReads` client-side by summing the per-year counts. Metrics split between *catalog-wide* (no user filter) and *user-scoped* (filtered by `auth()->id()`) — a distinction not visible in the response shape.

## How it's wired

### Backend

- **Routes** (`routes/api.php`, `auth:sanctum`):
  - `GET /api/statistics` → `StatisticsController::fetchUserStats` — single endpoint, no parameters.
- **Controllers**: `StatisticsController` is thin — one method that returns `$statisticsService->getUserStats()` as JSON.
- **Services**: `StatisticsService` holds every metric. `getUserStats()` is the canonical entrypoint and assembles the response from six private methods:
  - `calculateTotalBooks()` — `Book::count()`, **catalog-wide, not user-scoped**.
  - `calculateTotalBooksRead()` — books with at least one `ReadInstance` for the current user.
  - `calculateBooksReadByYear()` — `ReadInstance::selectRaw('YEAR(date_read) as year, COUNT(*) as total')`, user-scoped, `whereNotNull('date_read')`, grouped + ordered descending.
  - `calculateTotalPagesReadByYear()` — joins `versions`, sums `versions.page_count` per year, user-scoped, casts year/total to int in PHP.
  - `calculatePercentageOfBooksRead()` — recomputes the two totals above and rounds to 2 decimals.
  - `retrieveFiveMostRecentlyCreatedBooks()` — `Book::latest()->limit(5)`, **catalog-wide, not user-scoped**, no eager loads.
- **Models**: no dedicated model. Reads through `Book` (PK `book_id`) and `ReadInstance` (PK `read_instances_id`); see `books.md` for both.
- **Policies / authorization**: none. The endpoint is gated by `auth:sanctum`; user scoping is applied per-query via `auth()->id()` where it applies, and is intentionally absent from the catalog-wide metrics.
- **Migrations**: none specific to statistics — reads from `books`, `read_instances`, `versions`.

### Frontend

- **API layer**: **none.** `StatisticsDashboard.vue` calls `axios.get("/api/statistics")` directly, bypassing the `api/` layer (same layering violation as `UpdateBookReadInstance` in `read-history.md`).
- **Stores**: none. The response is held in `StatisticsDashboard.data().statistics`; no Pinia store backs it.
- **Service**: none.
- **Routes**: defined directly in `resources/js/router/index.js` as `name: 'statistics'`, path `/statistics` (not split into a per-feature routes file). Linked from `components/navs/SidebarNav.vue`.
- **Views**: `views/StatisticsDashboard.vue` — the only consumer.
- **Components**: none feature-specific. The dashboard is a flat grid of cards inline in the view.

## Non-obvious decisions and gotchas

- **Catalog metrics are not user-scoped.** `total_books` and `newestBooks` are global queries against `books` — every book in the database, regardless of who created it. `total_books_read`, `booksReadByYear`, and `totalPagesByYear` *are* user-scoped. The `percentageOfBooksRead` therefore divides a user-scoped numerator by a catalog-wide denominator. In a single-user instance this is fine; the moment book ownership exists (see `/feature-plans/books.md`), the percentage and "Newest Books" become wrong without rework.
- **`StatisticsDashboard` calls axios directly.** No `api/StatisticsController.js` wrapper exists. Adding one is the right move; until then, this is the place to grep when the route or response shape changes.
- **Response keys mix snake_case and camelCase.** `total_books`, `total_books_read`, `percentageOfBooksRead`, `booksReadByYear`, `totalPagesByYear`, `newestBooks` — three distinct conventions in one payload. Any new metric should pick one and document it; today the dashboard maps each key in `computed` so the inconsistency doesn't propagate to the template.
- **Per-year shapes differ.** `booksReadByYear` is returned as a collection of raw Eloquent rows where `year` is a MySQL string and `total` an int. `totalPagesByYear` is mapped to `[{ year: int, total: int }]` arrays. Consumers needing strict types must remember which is which — the dashboard's `v-for :key="year.year"` works either way only because Vue stringifies keys.
- **`booksReadByYear` counts reads, not unique books.** `COUNT(*)` over `read_instances` includes re-reads, so a user who read the same book twice in 2025 contributes 2 to that year. The dashboard's "Total Books Read Per Year" label is therefore misleading — it's actually "total reads with a date_read per year." `totalReads` (computed client-side as the sum of these) is correctly labeled "(incl. re-reads)".
- **`totalReads` excludes undated reads.** It's computed client-side as `booksReadByYear.reduce(...)`, and `booksReadByYear` filters `whereNotNull('date_read')`. A user with undated reads will see a `totalReads` smaller than their actual `read_instances` row count. `total_books_read` is *not* affected — it counts books with at least one read regardless of date.
- **Rating is not exposed at all.** Despite the doubled-on-write rating mutator (see `books.md`), no metric in this payload uses ratings — no average rating, no distribution, no per-year breakdown. The list-stats view computes an average rating client-side and halves it; if a server-side rating metric is ever added here, it must do the same halving.
- **`calculatePercentageOfBooksRead` re-runs the two count queries.** It calls `calculateTotalBooks()` and `calculateTotalBooksRead()` again rather than reusing the values already assembled in `getUserStats()`. Two extra queries per request; trivial today but worth knowing if the totals ever become expensive.
- **`newestBooks` is bare `Book` models.** No eager loading of authors, genres, or versions. The dashboard only renders `title` and `slug`, but any future component that needs richer detail will trigger N+1 fetches or have to re-request the books from `BookController::show`.
- **`YEAR()` and the join are MySQL-specific and not index-friendly.** `selectRaw('YEAR(date_read)')` and the implicit `read_instances ⋈ versions` join can't use an index on `date_read`; both year-aggregations will full-scan `read_instances` at scale. Same caveat as `BookService::getAvailableYears` (see `read-history.md`); whatever index strategy lands there should be reused here.
- **No caching.** `StatisticsDashboard` refetches on every mount. There's no Pinia store, no `If-None-Match`, no server-side cache. Cheap today; will be the obvious win once the dataset grows.

## Usage notes

`GET /api/statistics` (auth required) returns:

```
{
  total_books: int,                 // catalog-wide; not user-scoped
  total_books_read: int,            // unique books with ≥1 read by current user
  booksReadByYear: [                // user-scoped; excludes null date_read
    { year: "2026", total: 12 },    // year is a string here
    ...
  ],
  totalPagesByYear: [               // user-scoped; excludes null date_read and null page_count
    { year: 2026, total: 4321 },    // year is an int here
    ...
  ],
  percentageOfBooksRead: float,     // 0–100, 2 decimals; mixed-scope (see gotchas)
  newestBooks: [Book, ...]          // catalog-wide; latest 5 by created_at; bare model payload
}
```

No request parameters. The endpoint always returns the current user's view; `user_id` is taken from the session via `auth()->id()`.

## Related

- Plan file: `/feature-plans/statistics.md` — known limitations and future improvements.
- `/documentation/books.md` — `Book` / `ReadInstance` schema, the rating-doubling mutator, the user-scoping convention.
- `/documentation/read-history.md` — `ReadInstance` aggregation by year for the `/completed` surface; shares the same MySQL-specific `YEAR()` query shape.
- `/documentation/lists.md` — the *other* statistics surface, computed entirely client-side from the list show payload. Contrast: this doc covers the user-wide server-side aggregations.
