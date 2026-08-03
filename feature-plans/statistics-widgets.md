---
path: /feature-plans/
status: draft
---

# Configurable statistics surfaces

## Goal

Today there are two statistics surfaces and they share nothing. `StatisticsDashboard.vue` fetches a fixed six-key payload with a direct `axios` call and renders seven hand-written cards inline. `ListStatisticsView.vue` fetches a full list payload and derives eight metrics in `computed` properties, rendering six more hand-written cards that look almost — but not exactly — like the first set. Neither can borrow anything from the other.

Meanwhile five other plans queue up more of the same shape:

- `/feature-plans/authors.md` item 10 — author-level stats on the detail page.
- `/feature-plans/genres.md` item 12 — genre-level stats.
- `/feature-plans/formats.md` item 14 — format-level stats.
- `/feature-plans/admin.md` item 21 — operator-facing site stats.
- `/feature-plans/lists.md` item 16 — a server-side list statistics endpoint.

All five say some variant of "reuses the same aggregation logic that `StatisticsService` will end up with." That logic doesn't exist yet, and `StatisticsService` in its current form can't grow into it: `getUserStats()` is a single monolithic method with six hardcoded private helpers, no parameters, and no notion of what the numbers are *about*.

This plan builds both halves of the seam:

1. **Backend** — a metric registry keyed by `(metric, scope)`. A scope is "who/what are we measuring" (the current user, a list, later an author / genre / format). A metric is one number or series, computed on demand. One endpoint serves any combination.
2. **Frontend** — a widget registry plus a `StatisticsGrid` host. A statistics surface becomes a **declarative config object**: which widgets, which metrics feed them, what labels and spans they get. The grid derives the metric set from the config, issues exactly one request, and renders. Adding a stats page becomes writing a config file; adding a chart becomes registering a widget.

Non-goals: charts (the widget registry makes them a drop-in later once a library is picked), caching beyond a simple per-scope client cache, and the book-ownership question (owned by `/feature-plans/books.md`).

## Approach

### 0. Contract decisions, resolved up front

These are settled; the phases below assume them.

- **camelCase everywhere.** The current payload mixes `total_books`, `total_books_read` (snake) with `booksReadByYear`, `percentageOfBooksRead` (camel). Every metric key becomes camelCase. The SPA is the only consumer, so there is no deprecation window — the two consumers change in the same commit.
- **Uniform series shape.** Every per-year metric returns `[{ year: int, total: int }]`, ordered descending. Today `booksReadByYear` leaks raw Eloquent rows with a string `year` while `totalPagesByYear` is already mapped. Normalize both at the metric layer.
- **`booksReadByYear` splits in two.** It is renamed **`readsByYear`** (unchanged `COUNT(*)` semantics — re-reads count, label becomes "Reads Per Year") and joined by a new **`uniqueBooksReadByYear`** (`COUNT(DISTINCT book_id)`). Displayed numbers on the existing dashboard do not change; the label stops lying and the second metric is available to surfaces that want it.
- **`totalReads` moves server-side.** Currently derived in the view by summing `booksReadByYear`, which silently undercounts for users with undated reads. The metric counts `read_instances` rows with no `date_read` filter.
- **Ratings are halved at the metric layer.** `read_instances.rating` is stored doubled (see `/documentation/books.md`). Every rating metric returns display scale (0–5) as a float. Widgets never halve; that rule dies with this refactor.
- **Catalog-wide metrics stay catalog-wide, but declare themselves.** `totalBooks` and `newestBooks` still ignore the requesting user. Rather than silently mixing scopes, each metric class declares `isCatalogWide()`, and the response's `meta.catalogWide` lists the keys that ignored user scoping. When ownership lands (`/feature-plans/books.md`), flipping each one is a one-line change with a visible contract. The decision *whether* to flip stays in that plan.
- **List statistics move server-side.** A `list` scope joins the registry rather than staying a client-side derivation. One data path, and list metrics become reusable outside `ListStatisticsView` (list index, dashboard) without copy-paste.
- **Discarded copies are ignored by every metric, for now.** `versions.is_discarded` landed after this plan's first draft and cuts across the whole registry. Read-derived metrics are already settled by `/feature-plans/books.md`: reads aggregate over `read_instances`, and discarding a copy later doesn't undo having read it — so `readsByYear`, `totalReads`, `pagesReadByYear`, and the rating metrics keep counting discarded copies, deliberately. The catalog metrics are **not** settled, and this plan preserves rather than fixes them — see Open questions.

### 1. Backend: scopes, metrics, registry

New namespace `app/Statistics/`.

```
app/Statistics/
  Scope.php                       value object: type, id, userId; resolves + authorizes
  ScopeResolver.php               scope type string -> Scope (model lookup + policy check)
  Contracts/Metric.php            key(), supports(Scope), dependsOn(), isCatalogWide(), compute(Scope, MetricResults)
  MetricRegistry.php              key -> Metric; resolve requested keys, order by dependency, run once each
  MetricResults.php               computed-value bag passed to dependent metrics
  Support/ReadInstanceQuery.php   shared scoped base query + year aggregation helpers
  Metrics/
    TotalBooks.php                catalog-wide
    NewestBooks.php               catalog-wide
    TotalBooksRead.php
    TotalReads.php
    ReadsByYear.php
    UniqueBooksReadByYear.php
    PagesReadByYear.php
    PercentageOfBooksRead.php     dependsOn: totalBooks, totalBooksRead
    AverageRating.php
    RatingDistribution.php        (new; cheap once the base query exists)
    TotalItems.php                list scope
    CompletedCount.php            list scope
    CompletedPercent.php          list scope; dependsOn: totalItems, completedCount
    TotalPages.php                list scope
    GenreBreakdown.php            list scope (later: user scope too)
```

**`Scope`** carries `type` (`user` | `list`, later `author` | `genre` | `format` | `admin`), an optional `id`, the resolved model, and `userId` from `auth()->id()`. `ScopeResolver` maps the type string to a model lookup plus an authorization check — `list` runs `BookListPolicy::view` (which already exists), `user` needs none beyond `auth:sanctum`. An unknown scope type is a 404; a failed policy check is a 403.

**`Metric::supports(Scope $scope)`** is what makes one registry serve every surface. `AverageRating` supports both `user` and `list` today and will support `author` for free once that scope resolves — the metric's job is to build its query from `$scope`, not to know which page called it.

**Dependency resolution** fixes the double-query bug in `calculatePercentageOfBooksRead`, which today re-runs both count queries it depends on. `MetricRegistry` walks `dependsOn()`, computes each metric at most once per request, and passes results through `MetricResults`. Dependencies are computed even when not requested, but only requested keys appear in the response.

**`Support/ReadInstanceQuery`** is the shared aggregation helper the authors / genres / formats plans keep pointing at. It builds the user-scoped `read_instances` base query and exposes `groupedByYear($select)`. One thing lands here:

- The `versions` join in `PagesReadByYear` gains a `read_instances.book_id = versions.book_id` predicate. Today it joins on `version_id` alone, so a mismatched read instance contributes the wrong page count. (`ReadInstance::booted()` now blocks new mismatches, but historical rows may exist.)

**No index migration is needed.** An earlier draft of this plan called for a `(user_id, date_read)` composite index and coordination with `/feature-plans/read-history.md` item 17 so it landed once. It already exists — `database/migrations/2024_01_21_044437_create_read_instances_table.php` creates `(user_id, date_read)`, `(book_id, date_read)`, and `(user_id, book_id)` at table creation. Don't add a second one. `/feature-plans/statistics.md` item 4 makes the same wrong assumption; the half of it that's still real is the `YEAR(date_read)` → `whereBetween` rewrite, which is a query-shape change on top of an index that's already there.

**Endpoint.** One route replaces the current one:

```php
// routes/api.php, inside the auth:sanctum group
Route::get('/statistics/{scope?}/{scopeId?}', [StatisticsController::class, 'show']);
```

`GET /api/statistics` keeps working — `scope` defaults to `user`. Adding an author or genre scope later is a registry entry and a `ScopeResolver` case, with **no route churn**, which is the point.

`StatisticsRequest` (new `FormRequest`) validates `metrics` as a comma-separated list against the registry's keys for the resolved scope. Unknown keys are a 422 rather than a silent omission — a typo in a surface config should fail loudly in dev. Omitting `metrics` returns every metric the scope supports.

`StatisticsController::show` becomes: resolve scope → validate → `$registry->compute($scope, $keys)` → JSON. Response:

```
{
  scope:   { type: "user", id: null },
  metrics: { totalBooksRead: 214, readsByYear: [{ year: 2026, total: 31 }, …], … },
  meta:    { catalogWide: ["totalBooks", "newestBooks"] }
}
```

Each metric is computed in its own `try`/`catch`. A metric that throws is reported in `meta.failed` and omitted from `metrics`, so one orphaned `version_id` degrades a single card instead of 500-ing the whole dashboard (a limitation called out in the living plan).

**`StatisticsService` becomes a thin façade** over `MetricRegistry` — or disappears entirely. It has no callers outside `StatisticsController`; deleting it and injecting the registry is cleaner, and the file's whole contents move to `app/Statistics/Metrics/`.

**List-scope metric parity.** Porting the eight `ListStatisticsView` computeds to SQL requires reproducing two quirks deliberately:

- `totalItems` and `completedCount` **dedupe by `book_id`** (a list may hold paperback + audiobook of one book), but `totalPages` currently sums **every** item without deduping. That inconsistency is preserved as-is on the port so numbers don't move underneath the user — see Open questions.
- `averageRating` on a list averages the user's reads of *any* version of each listed book, not just the listed version. Preserve; document in the metric class.

### 2. Frontend: api → store → registry → grid

```
resources/js/
  api/StatisticsController.js            getStatistics(scope, scopeId, metricKeys)
  stores/StatisticsStore.js              per-scope cache, in-flight dedupe, invalidation
  services/statistics/
    widgetRegistry.js                    widget key -> { component, propsFromMetrics }
    formatters.js                        number | percent | rating | compact
    surfaces/index.js                    surface key -> config
    surfaces/userStatistics.js           /statistics  (full set)
    surfaces/userDashboard.js            /dashboard   (summary set)
    surfaces/listStatistics.js           /lists/:id/statistics
  components/statistics/
    StatisticsGrid.vue                   the host
    WidgetShell.vue                      grid span, heading, loading / error / empty states
    widgets/StatTile.vue                 big number + label (+ suffix, formatter)
    widgets/SeriesList.vue               year -> total list
    widgets/BreakdownList.vue            name + count, optionally selectable
    widgets/EntityLinkList.vue           list of router-links (newest books)
```

**`api/StatisticsController.js`** finally removes the direct `axios` import from the dashboard, using `makeRequest` / `buildUrl` like every other controller. One snag: `buildUrl(entity, id)` builds a single path segment (`/api/<entity>/<id>`), so it can't express `/api/statistics/list/12` as-is. Either pass the composed tail (`` buildUrl("statistics", `${scope}/${scopeId}`) ``) or widen the helper to accept segments — decide in this phase rather than discovering it mid-build. Note `buildUrl` emits a trailing slash when `id` is falsy, so the bare `/api/statistics/` case must still match the optional-parameter route.

**The `@` alias is fine.** `CLAUDE.md` flags it as unverified (there's no `resolve.alias` in `vite.config.js`), and this plan adds `@/` imports across four new directories. Verified empirically before starting: both `npm run build` and `vitest run` resolve `@/…` today. Not a blocker — but making it explicit is still worth folding in here, since `/feature-plans/frontend-tests.md` wants it before component tests land anyway.

**`StatisticsStore`** caches by scope key (`"user"`, `"list:12"`). It tracks which metric keys are already cached for a scope and requests only the missing ones, merging into the cached bag — so `/dashboard` (summary set) followed by `/statistics` (full set) fetches only the delta. Concurrent requests for the same scope dedupe on a shared promise. `invalidate(scopeKey)` is called after any read-instance write and after list mutations; `refresh()` backs a manual retry button. This is also what lets a future header badge read `totalBooksRead` without its own fetch.

**`widgetRegistry.js`** maps a widget key to a component plus a `propsFromMetrics(metrics, entry)` function. Only four widgets are needed to cover both existing surfaces — every card on both pages today is a `StatTile`, a `SeriesList`, a `BreakdownList`, or an `EntityLinkList`.

**A surface config** is plain data:

```js
// services/statistics/surfaces/userStatistics.js
export default {
    key: "userStatistics",
    scope: { type: "user" },
    widgets: [
        {
            id: "uniqueRead",
            widget: "statTile",
            span: 3,
            metrics: { value: "totalBooksRead" },
            props: { label: "Unique Books Read" },
        },
        {
            id: "totalReads",
            widget: "statTile",
            span: 3,
            metrics: { value: "totalReads" },
            props: { label: "Total Reads (incl. re-reads)" },
        },
        {
            id: "percentRead",
            widget: "statTile",
            span: 3,
            metrics: { value: "percentageOfBooksRead" },
            props: { label: "Percentage of Catalog Read", format: "percent" },
        },
        {
            id: "readsByYear",
            widget: "seriesList",
            span: 4,
            metrics: { series: "readsByYear" },
            props: { label: "Reads Per Year", xKey: "year", yKey: "total" },
        },
        {
            id: "avgRating",
            widget: "statTile",
            span: 4,
            metrics: { value: "averageRating" },
            props: { label: "Avg. Rating (out of 5)", format: "rating" },
            visibleWhen: (m) => m.averageRating !== null,
        },
    ],
};
```

`visibleWhen` is required by both surfaces today — the list page hides the rating card when nothing is rated and the genre card when there are no genres.

**`StatisticsGrid.vue`** takes a surface config plus runtime scope params (`:scope-id="$route.params.id"`) and does five things:

1. Union every `metrics` value across visible widgets → the requested metric key list.
2. Ask `StatisticsStore` for that scope + those keys → **one** HTTP request per surface, regardless of widget count.
3. Render the `grid-cols-12` container (lifted verbatim from the existing markup) and place each widget in a `WidgetShell` at its declared span.
4. Own loading / error / empty for the whole surface — `PageLoadingIndicator` while in flight, `AlertBox` plus a retry button on failure, and per-widget "unavailable" state for anything in `meta.failed`. All four states are missing from `StatisticsDashboard` today.
5. Re-emit widget interactions upward as `@widget-event="{ widgetId, name, payload }"`.

That last point keeps drill-downs where they belong. `BreakdownList` emits `select`; `ListStatisticsView` catches `widget-event`, sets `selectedGenre`, and renders the filtered `BookshelfTable` beneath the grid — exactly today's behavior, but the widget stays generic and reusable for a future "by genre" pivot on the user dashboard.

### 3. Views become thin

- **`StatisticsDashboard.vue`** → `<StatisticsGrid :surface="userStatistics" />` plus a heading. All seven cards, both computeds-over-computeds, the axios import, and the `v-if="statistics"` guard are deleted.
- **`UserDashboard.vue`** stops being a placeholder and becomes `<StatisticsGrid :surface="userDashboard" />` — a smaller widget set over the same scope. Since `StatisticsStore` caches per scope, navigating `/dashboard` → `/statistics` fetches only the metrics the summary set didn't already pull. This also fixes the "every login lands on a placeholder" complaint in `/feature-plans/auth.md` item 11 and `/feature-plans/app-shell.md` item 9, and it is the proof that two surfaces genuinely share widgets under different configs.
- **`ListStatisticsView.vue`** keeps its heading, back-link, and genre drill-down table; the eight computeds and all six cards are deleted in favor of `<StatisticsGrid :surface="listStatistics" :scope-id="$route.params.id" />`. The view still needs the list payload for the list name and the drill-down table, but it now reads `ListsStore.currentList` and only fetches on a cache miss — which also fixes the triple-GET flagged in `/feature-plans/lists.md`.

### 4. Phasing

Each phase leaves the app working.

1. **Backend registry, existing metrics only.** `app/Statistics/` scaffolding, `user` scope, the eight current metrics under normalized camelCase keys, dependency resolution, the new generic route. Update `tests/Feature/Statistics/StatisticsTest.php` for the new key names — note that `test_percentage_of_books_read_uses_global_book_count` and `test_newest_books_returns_five_most_recent_globally` intentionally pin the catalog-vs-user split; they get renamed keys and an added assertion on `meta.catalogWide`, not relaxed semantics.
2. **New user-scope metrics.** `totalReads`, `uniqueBooksReadByYear`, `averageRating`, `ratingDistribution`, plus the `PagesReadByYear` join fix. No migration — the `(user_id, date_read)` index already exists (see §1), so nothing here is coupled to `/feature-plans/read-history.md`.
3. **Frontend plumbing.** `api/StatisticsController.js` + `StatisticsStore` + tests. `StatisticsDashboard` switches to the store but keeps its inline markup — the layering violation dies here, independently of the widget work.
4. **Widget registry + grid.** The four widgets, `WidgetShell`, `StatisticsGrid`, formatters, and `userStatistics` surface config. `StatisticsDashboard` becomes one line.
5. **`UserDashboard`.** `userDashboard` surface config; delete the placeholder markup.
6. **`list` scope.** List-scope metrics server-side, `ScopeResolver` policy wiring, `listStatistics` surface, `ListStatisticsView` migrated with its genre drill-down intact. Assert parity against the old client-side numbers on real data before deleting the computeds.
7. **Docs + changelog.** Rewrite `/documentation/statistics.md` around the registry and the config-driven surfaces; add a "how to add a metric" and "how to add a surface" section — the whole point is that both are short. Update `/documentation/lists.md` (stats are no longer client-derived) and `/feature-plans/lists.md` items 4 / 16, which this supersedes. Flip this plan to `living`.

### 5. Testing

**Backend** (`tests/Feature/Statistics/`): one test per metric class covering zero-data, single-user isolation, and its specific quirk (re-reads for `readsByYear`, undated reads for `totalReads` vs `readsByYear`, doubled ratings halved for `averageRating`, dedupe-by-book for `totalItems`). Registry-level tests for unknown metric key → 422, unauthorized list scope → 403, unknown scope → 404, `metrics` omitted → all supported keys, dependency computed exactly once, and a throwing metric landing in `meta.failed` without failing the response.

**Frontend** (per `/feature-plans/frontend-tests.md` conventions): `tests/api/StatisticsController.test.js` (URL/verb/param shape), `tests/stores/StatisticsStore.test.js` (cache hit, partial-metric merge, in-flight dedupe, invalidation), and `tests/services/statistics/surfaces.test.js` — a cheap guard that every surface's widget keys exist in the registry and every metric key it references exists in a fixture of the backend catalog. That last test is what stops a config typo shipping. Widget component tests wait on `@vue/test-utils`, which `/feature-plans/frontend-tests.md` already schedules.

## Touches existing systems

- **`app/Services/StatisticsService.php`** — fully absorbed into `app/Statistics/Metrics/`; likely deleted. Anything landing new metrics there before this ships will need porting.
- **`routes/api.php`** — `GET /statistics` is replaced by `GET /statistics/{scope?}/{scopeId?}`. Old path still resolves.
- **`tests/Feature/Statistics/StatisticsTest.php`** — every assertion keys off the old response shape; all five tests change. The `ReadInstanceFactory` leak that used to block literal-count assertions here is **fixed** (prework, see `/feature-plans/backend-tests.md`), so metric tests can now assert exact row counts.
- **`read_instances` indexing** — nothing to do. The `(user_id, date_read)` index has existed since the table was created; see §1.
- **`ListStatisticsView.vue` / `ListsStore`** — this supersedes `/feature-plans/lists.md` item 4 (the `useListStatistics` composable seam — no longer needed, the metrics move server-side) and implements item 16 ahead of the pagination work that plan assumed would gate it. Update both when this lands.
- **`UserDashboard.vue` and the post-login redirect** — owned by `/feature-plans/auth.md` item 11 and `/feature-plans/app-shell.md` item 9. This plan resolves the "what is `/dashboard`" question in favor of a summary statistics surface; both plans need their items closed out.
- **`/feature-plans/authors.md` item 10, `/feature-plans/genres.md` item 12, `/feature-plans/formats.md` item 14** — these are the intended beneficiaries. Once this ships, each becomes "add a `ScopeResolver` case + a surface config," not new aggregation code. None of them should start before this lands, or they'll write the logic three more times.
- **`/feature-plans/admin.md` item 21** — a site-stats dashboard is an `admin` scope plus a config; it needs a scope-level authorization check (admin-only) that `ScopeResolver` should accommodate from the start.
- **`/feature-plans/books.md`** — book ownership is what eventually forces `totalBooks` / `newestBooks` to become user-scoped. `isCatalogWide()` + `meta.catalogWide` exist to make that flip a one-line, visible change; the decision itself stays there.

## Open questions

- **Should the catalog metrics respect the shelf?** `TotalBooks` (`Book::count()`) and `NewestBooks` (`Book::latest()->limit(5)`) ignore the "a book is discarded only when *every* version is" rule that `BookController::applyDiscardedFilter` enforces for `LibraryView`. So `percentageOfBooksRead` divides a user-scoped numerator by a denominator that includes books no longer on the shelf, and `newestBooks` can link to a book the library itself won't list. List-scope `totalPages` has the same shape — it sums discarded copies.

  This is a second scoping axis, independent of the user-scoping axis that `isCatalogWide()` / `meta.catalogWide` track, and it should be a declared flag on `Metric` from the start rather than retrofitted alongside book ownership. Phase 1 preserves current behavior (it's a refactor) and documents the quirk in each metric class. If the answer turns out to be "yes, respect the shelf," the prerequisite is `/feature-plans/books.md` item 11 — moving the filter out of the private controller method into a `Book::scopeOnShelf()` — so the rule isn't reimplemented per metric.
- **Should list `totalPages` dedupe by book?** Today it doesn't, while `totalItems` and `completedCount` do — so a list holding the paperback *and* the audiobook of one book counts one book but two page counts. Porting preserves the inconsistency. Fixing it changes a visible number, which is a product call, not a refactor call.
- **What is `pagesReadByYear` for audiobook-heavy years?** `versions.audio_runtime` is populated but never aggregated, so an audiobook year reads as near-zero pages. Adding `audioRuntimeByYear` is trivial once the base query exists (`/feature-plans/statistics.md` item 10), but whether the dashboard shows two separate series or one normalized "time read" estimate needs a decision.
- **Chart library.** The widget registry makes a `barChart` / `lineChart` widget a drop-in — same config shape, same metric keys, different component. No library is picked and none should be picked as part of this refactor; `SeriesList` ships first.
- **Server-side caching.** The client store covers the "bounce between pages" case. Whether the endpoint also wants a short-TTL per-user cache invalidated on `ReadInstance` write is worth revisiting once metric count grows past ~15 per surface, not now.
