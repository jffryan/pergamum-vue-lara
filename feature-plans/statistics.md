---
path: /feature-plans/
status: living
---

# Statistics

Tracks rough edges and follow-up work for the user-wide statistics dashboard (`GET /api/statistics`, `StatisticsDashboard.vue`). Descriptive content lives in `/documentation/statistics.md`. Per-list statistics are owned by `/feature-plans/lists.md`; year-browse aggregations are in `/feature-plans/read-history.md`. Cross-cutting items link there rather than restate.

## Known limitations

### Authorization & scoping

- **Catalog metrics are not user-scoped.** `total_books` and `newestBooks` ignore the requesting user — `Book::count()` and `Book::latest()->limit(5)`. The moment book ownership lands (see `/feature-plans/books.md`), both metrics become wrong, and `percentageOfBooksRead` (which divides a user-scoped numerator by a catalog-wide denominator) becomes nonsensical.
- **The catalog-vs-user split is now pinned by tests.** `tests/Feature/Statistics/StatisticsTest.php::test_percentage_of_books_read_uses_global_book_count` and `::test_newest_books_returns_five_most_recent_globally` lock the current (catalog-wide) behavior of `total_books`, `newestBooks`, and the percentage denominator. Any refactor that introduces user scoping must update those tests deliberately rather than silently flipping the contract.

### Validation & request shape

- **Endpoint takes no parameters today.** Future "stats for year X" or "stats since date Y" filters have nowhere to plug in; expect a query-string contract to need design.
- **No `FormRequest` exists yet** — fine while the route is parameterless, but worth noting before adding filters.

### Response shape & semantics

- **Mixed casing in the response.** `total_books`, `total_books_read` (snake) vs. `booksReadByYear`, `totalPagesByYear`, `percentageOfBooksRead`, `newestBooks` (camel). Pick one and migrate; this leaks into the SPA's computed-property names and is the kind of thing nobody fixes once a dashboard depends on it.
- **`booksReadByYear` returns raw Eloquent rows.** `year` is a MySQL string; `total` is an int. `totalPagesByYear` already maps to typed `[{ year: int, total: int }]`. Normalize both.
- **"Books Read Per Year" is actually "reads with a date per year."** `COUNT(*)` over `read_instances` counts re-reads. The label in `StatisticsDashboard.vue` is misleading. Either rename the label, or split into two metrics: `readsByYear` (current behavior) and `uniqueBooksReadByYear` (`COUNT(DISTINCT book_id)`).
- **`totalReads` excludes undated reads.** Computed client-side from `booksReadByYear`, which filters `whereNotNull('date_read')`. A user with undated reads sees a smaller number than their `read_instances` row count. Either expose a server-side `total_reads` that doesn't filter on date, or document the discrepancy in the UI.
- **No rating metrics anywhere.** No average rating, no distribution, no per-year average. The data is there (rating is on `read_instances`, doubled at rest); a future contributor will likely add it. Whoever does must halve before display — see the rating-doubling note in `/documentation/books.md`.
- **`newestBooks` is bare `Book` models.** No eager-loaded authors / genres / versions; the dashboard renders only `title` + `slug`, but any richer surface will need eager loading or a refetch.
- **No total reading-time estimate, no audio runtime aggregation.** `totalPagesByYear` exists; `totalAudioRuntimeByYear` does not, despite `versions.audio_runtime` being populated. Audiobook-heavy users see misleadingly low numbers.

### Performance & query shape

- **`YEAR(date_read)` cannot use an index.** Both `calculateBooksReadByYear` and `calculateTotalPagesReadByYear` will full-scan `read_instances` at scale. Same fix as `/feature-plans/read-history.md`: range queries (`date_read BETWEEN '$year-01-01' AND '$year-12-31'`) plus a `(user_id, date_read)` composite index. Coordinate the index migration with read-history so it lands once.
- **`calculatePercentageOfBooksRead` re-runs both totals.** Two extra queries per request; trivial today but should reuse the already-computed values from `getUserStats`.
- **`getUserStats` issues 6+ queries with no caching.** No Pinia store on the client, no server-side cache, no `If-None-Match`. Refetched on every mount of the dashboard. Memoize per-user with a short TTL once cardinality grows.
- **The `versions` join in `calculateTotalPagesReadByYear` ignores `version_id` mismatches.** It joins on `read_instances.version_id = versions.version_id` without filtering by `book_id`, so any cross-book mismatched read instance (currently possible — see `/feature-plans/read-history.md`) silently contributes the wrong page count.

### Error handling

- **`StatisticsController::fetchUserStats` doesn't catch.** Any exception bubbles to a 500 with debug payload in dev. No transaction (read-only, fine), but a malformed `read_instances` row (e.g. orphaned `version_id`) takes down the whole dashboard rather than degrading gracefully.
- **`StatisticsDashboard.vue` swallows fetch errors.** On non-200 it `console.error(err)` and leaves `statistics` as `null`. The template's `v-if="statistics"` then renders nothing — no error state, no retry, no skeleton.

### Frontend & UX

- **Direct `axios` import in `StatisticsDashboard.vue`.** Violates the layering rule from `CLAUDE.md` (`views → services/stores → api → axios`). Add a `getUserStatistics` wrapper to a new `api/StatisticsController.js` and route through it.
- **No charts.** Per-year totals are rendered as a list of `<span>`s. A bar / line chart over years would be the obvious upgrade; deferred until a chart library is picked.
- **No empty state.** A user with no reads sees the four stat cards (zero / zero / catalog total / 0%) and two empty per-year sections. No "log your first read" CTA.
- **No loading state.** While the request is in flight `statistics` is `null` and nothing renders — not even a spinner. `PageLoadingIndicator` exists and is used elsewhere; reuse it here.
- **`UserDashboard.vue` is a placeholder.** It says "You're logged in!" and nothing else, but the sidebar still routes to it. Either fold the stats dashboard into the user dashboard or delete `UserDashboard.vue`.
- **Sidebar nav is the only entry point.** No card on a home page, no link from `LibraryView`, no contextual "see your stats for this author" affordance.

### Extensibility

- **No tests for `StatisticsService` at all.** None of the six private methods is covered. Necessary before any of the structural cleanup above.
- **No store, no service-layer composition.** The dashboard view holds the response in `data()`. A future surface that wants to read `total_books_read` (e.g. a header badge, a year-summary card on the home page) will refetch from scratch.
- **`getUserStats` is monolithic.** All metrics computed in one pass; no way to ask "just give me the per-year reads." If a chart view wants only one slice, it currently pays for all six. Splitting into individual endpoints (or accepting a `?metrics=` filter) is a likely future need.
- **MySQL-specific `YEAR()`.** Locks the dashboard to MySQL. Same coupling as `read-history.md`; portable rewrite (`EXTRACT(YEAR FROM date_read)` or range filtering) should land in lockstep across both.
- **No genre / author / format dimensions.** Today everything is "by year." A "by genre" or "by author" pivot is an obvious extension; the schema supports it but the service has no helpers for it yet.

## Future improvements

In rough priority order.

1. **Add Feature tests** for `GET /api/statistics`: shape of every key, user scoping where it applies, behavior with zero books / zero reads / undated reads / re-reads, and explicit assertions on the catalog-wide vs. user-scoped split (so that boundary doesn't regress accidentally).
2. **Add `api/StatisticsController.js`** with `getUserStatistics()` and route `StatisticsDashboard.vue` through it. Removes the direct axios import.
3. **Loading + error states in `StatisticsDashboard`.** Reuse `PageLoadingIndicator` and `AlertBox` (the same pattern `LibraryView` uses).
4. **Switch year filtering to range queries.** Replace `YEAR(date_read)` with `whereBetween` + a `(user_id, date_read)` index. Coordinate the migration with `/feature-plans/read-history.md` so the index lands once.
5. **Reuse the totals inside `calculatePercentageOfBooksRead`.** Pass them in instead of re-running the queries.
6. **Normalize the response casing and per-year shapes.** Pick camelCase end-to-end (or snake_case — but the SPA convention leans camel). Map `booksReadByYear` rows to `{ year: int, total: int }` to match `totalPagesByYear`.
7. **Decide what "books read" means.** Either rename the existing metric to `readsByYear` and add `uniqueBooksReadByYear` as a separate count, or change the SQL to `COUNT(DISTINCT book_id)`. The dashboard label and the meaning of `totalReads` follow from this choice.
8. **Surface undated reads.** Add a `totalReads` server-side field that doesn't filter on `date_read`, so the SPA stops needing to derive it (and stops being silently wrong for undated-read users).
9. **Add rating metrics.** Average rating overall, per year, and a rating distribution. Halve at the service layer (or document that callers must halve) — match the convention in `/documentation/books.md`.
10. **Add `audioRuntimeByYear`** alongside `totalPagesByYear`. Trivial change to the existing join.
11. **Eager-load `newestBooks`.** At minimum `authors`; without slug-based author rendering it's the only thing missing for a richer card.
12. **Cache the response.** Either a per-user Laravel cache with a short TTL invalidated on `ReadInstance` write, or a Pinia store with manual refresh. Keep the contract: dashboard mount = current data.
13. **Address the catalog-scope question before book ownership lands.** Two paths: (a) make `total_books` and `newestBooks` user-scoped now, accepting the change in numbers; or (b) split the response into `catalog: { … }` and `user: { … }` sections so the boundary is explicit. Pick before `/feature-plans/books.md` introduces ownership.
14. **Fold or delete `UserDashboard.vue`.** It's a placeholder; either it becomes the stats dashboard or it goes away.
15. **Charts.** Pick a small chart library (Chart.js / ApexCharts / a Vue wrapper) and replace the per-year `<span>` lists with a bar chart for reads-per-year and a line chart for pages-per-year.
16. **Add a "by genre" / "by author" / "by format" pivot.** Schema supports it; service helpers don't exist yet. Likely the first significant extension once the existing metrics stabilize.
17. **Coordinate with `/feature-plans/read-history.md` and `/feature-plans/lists.md`.** All three feature areas re-implement variations on "user-scoped read aggregation." Once the index strategy and shared helpers exist, the per-list and per-year aggregations should reuse them rather than each rolling their own.
