---
path: /documentation/
status: living
---

# Statistics

## Scope

Covers every statistics surface in the app: the user-wide dashboard at `/statistics`, the post-login summary at `/dashboard`, and per-list statistics at `/lists/:id/statistics`. All three go through one endpoint (`GET /api/statistics/{scope?}/{scopeId?}`), one backend metric registry (`app/Statistics/`), and one frontend widget registry plus grid host. Does **not** cover the year-browse "Completed" surface, which is read-history aggregation — see `read-history.md`.

## Summary

A **metric** is one number or one series, computed on demand. A **scope** is what the numbers are about — the requesting user, or one of their lists (authors, genres, formats and an admin scope are the intended next entries). Metrics declare which scopes they support, which other metrics they depend on, and which caveats apply to them; the registry resolves a requested key list into a response.

On the frontend a **surface** is a plain config object: which widgets, fed by which metrics, with what labels and grid spans. `StatisticsGrid` reads a surface, derives the metric set from it, asks `StatisticsStore` for exactly that set, and renders. Adding a statistics page is writing a config file; adding a chart is registering a widget.

## How it's wired

### Backend (`app/Statistics/`)

```
Scope.php                  value object: type, userId, id, resolved model
ScopeResolver.php          scope type string -> Scope (lookup + authorization)
Contracts/Metric.php       key, supports, dependsOn, isCatalogWide, isShelfScoped,
                           isEstimated, estimationMeta, compute
AbstractMetric.php         defaults — user-scoped, measured, no dependencies
MetricRegistry.php         key -> metric; dependency ordering, per-metric error isolation
MetricResults.php          computed-value bag handed to dependent metrics
Support/ReadInstanceQuery  shared read-history base query + groupedByYear()
Support/ListQuery          shared list-membership queries
Metrics/*.php              one class per metric
```

- **Route** (`routes/api.php`, `auth:sanctum`): `GET /api/statistics/{scope?}/{scopeId?}` → `StatisticsController::show`. `scope` defaults to `user`, so `GET /api/statistics` still works.
- **Request**: `App\Http\Requests\StatisticsRequest` resolves the scope (which is also where it gets authorized) and validates `?metrics=` — a comma-separated list — against the keys the resolved scope supports.
- **Controller**: `StatisticsController::show` is four lines: resolve scope, compute, respond.
- **Registration**: `config/statistics.php` lists the metric classes and holds the estimate constants. `AppServiceProvider` builds the singleton `MetricRegistry` from it.
- **Authorization**: `auth:sanctum` covers the user scope. The list scope runs `BookListPolicy::view` through `Gate::authorize`, so someone else's list is a 403 rather than an empty page.

### Frontend

```
api/StatisticsController.js              getStatistics(scope, scopeId, metricKeys)
stores/StatisticsStore.js                per-scope cache, partial fetch, dedupe, invalidation
services/statistics/formatters.js        number | percent | rating | duration | compact
services/statistics/footnotes.js         caveats generated from meta
services/statistics/widgetRegistry.js    widget key -> component + props mapping
services/statistics/surfaces/*.js        userStatistics, userDashboard, listStatistics
components/statistics/StatisticsGrid.vue the host
components/statistics/WidgetShell.vue    span, heading, footnote, unavailable state
components/statistics/widgets/*.vue      StatTile, SeriesList, BreakdownList, EntityLinkList
```

Views are thin: `StatisticsDashboard.vue`, `UserDashboard.vue`, and `ListStatisticsView.vue` each pass a surface config to `StatisticsGrid`. `ListStatisticsView` keeps its own list payload for the heading and the genre drill-down table, because those need the items themselves rather than an aggregate.

## Non-obvious decisions and gotchas

- **The response describes its own caveats.** `meta` carries three lists, each populated from a declaration on the metric class rather than a hand-maintained list in the controller, so a new metric can't silently omit itself:
  - `catalogWide` — *whose* data: the metric ignores user scoping (`totalBooks`, `newestBooks`).
  - `shelfScoped` — *which* copies: fully-discarded books are excluded (`newestBooks`).
  - `estimated` — *how sure*: the value is derived, with the conversion factors echoed.

  The axes are orthogonal. `newestBooks` appearing in two lists is the point, not a bug.

- **`totalBooks` is catalogue-wide because it has to be.** It's the denominator of `percentageOfBooksRead`, whose numerator counts books the user has read *including discarded ones*. Shelf-scoping the denominator alone would let the percentage exceed 100. `tests/Feature/Statistics/StatisticsTest.php::test_percentage_cannot_exceed_one_hundred_when_books_are_discarded` is the guard.

- **`newestBooks` is shelf-scoped** — it excludes books whose every version is discarded, via `Book::scopeOnShelf()`. It sits in no ratio, so nothing forces its axis, and bulk upload makes this matter: `created_at` is import time, not acquisition time, so importing a historical backlog would otherwise fill the card with books you no longer own.

- **Reads keep discarded copies.** Every read-derived metric aggregates over `read_instances` without consulting discard state. Getting rid of a book later doesn't undo having read it.

- **Counts of works dedupe by book; measures of physical volume do not.** `totalItems` and `completedCount` answer "how many distinct books", so a paperback and an audiobook of one novel is one book. `totalPages` answers "how much shelf is this", and two genuinely distinct copies are two real objects with real pages — so it sums every version, undeduped, deliberately. This is why the list surface labels it "Total Pages (all copies)": without the qualifier a reader divides one number by the other.

- **Ratings are halved at the metric layer.** `read_instances.rating` is stored doubled (see `books.md`); every rating metric returns the 0–5 display scale as a float. Widgets never halve.

- **`readsByYear` counts reads; `uniqueBooksReadByYear` counts books.** The old `booksReadByYear` was the former under a label claiming the latter. Both are available; the label no longer lies.

- **`totalReads` does not filter on `date_read`.** The dashboard used to derive it by summing the per-year counts, which silently undercounted for anyone with undated reads.

- **Audiobooks store zero pages, not null.** `versions.page_count` is `NOT NULL` and `BulkImportService` writes 0 for audio rows, so an audiobook contributes nothing to a page sum and can't double-count the book it shares. `estimatedTotalPagesByYear` adds `audioRuntimeByYear × pagesPerAudioMinute` on top; the factor lives in `config/statistics.php` and is echoed in `meta.estimated` because it is a stated assumption, not a fact.

- **The version join carries a `book_id` predicate.** `ReadInstanceQuery::joinVersions()` joins on both `version_id` and `book_id`. `ReadInstance::booted()` blocks new mismatches, but a historical row whose version belongs to another book would otherwise contribute that book's page count.

- **One failing metric degrades one card.** Each metric computes inside its own `try`/`catch`; a failure is logged, listed in `meta.failed`, and omitted from `metrics`. Dependents of a failed metric fail too and are listed as well. `WidgetShell` renders "Unavailable right now" for those, and the rest of the surface loads.

- **Dependencies compute once.** `MetricRegistry` topologically orders `dependsOn()` and passes results through `MetricResults`. Dependencies are computed even when not requested, but only requested keys appear in the response — `?metrics=percentageOfBooksRead` returns exactly one key and runs two count queries, not four.

- **Unknown metric keys are a 422.** A typo in a surface config fails loudly rather than rendering a quietly missing card. `resources/js/tests/services/statistics/surfaces.test.js` catches it earlier still, by checking every surface's keys against a mirror of `config/statistics.php`.

- **The store fetches deltas.** `StatisticsStore` caches per scope key (`"user"`, `"list:12"`) and tracks which keys have been *requested* — not just which came back, so a metric that failed server-side isn't re-requested on every visit. Visiting `/dashboard` then `/statistics` fetches only the metrics the summary set didn't already pull. Concurrent requests for one scope share a promise.

- **Invalidation is explicit.** `StatisticsStore.invalidateAll()` after a read-instance write (a new read moves numbers on every scope), `invalidate("list", id)` after list item add/remove.

## Usage notes

`GET /api/statistics` — the user scope, every metric it supports.
`GET /api/statistics/list/12?metrics=totalItems,completedCount` — one list, two metrics.

```
{
  scope:   { type: "user", id: null },
  metrics: {
    totalBooksRead: 214,
    readsByYear: [{ year: 2026, total: 31 }, …],   // always [{year:int,total:int}], newest first
    …
  },
  meta: {
    catalogWide: ["totalBooks", "newestBooks"],
    shelfScoped: ["newestBooks"],
    estimated:   { estimatedTotalPagesByYear: {
                     pagesPerAudioMinute: 0.55,
                     from: ["pagesReadByYear", "audioRuntimeByYear"],
                     converted: "audioRuntimeByYear"
                   } },
    failed: []
  }
}
```

Metric keys are camelCase throughout. Omitting `?metrics=` returns everything the scope supports; an unknown key for that scope is a 422, an unknown scope type a 404, and a list you don't own a 403.

**User scope**: `totalBooks`, `totalBooksRead`, `percentageOfBooksRead`, `totalReads`, `readsByYear`, `uniqueBooksReadByYear`, `pagesReadByYear`, `audioRuntimeByYear` (minutes), `estimatedTotalPagesByYear`, `averageRating` (0–5 or null), `ratingDistribution`, `newestBooks`.

**List scope**: `totalItems`, `completedCount`, `completedPercent`, `totalPages`, `genreBreakdown`, `averageRating`, `ratingDistribution`.

### Adding a metric

1. Write a class in `app/Statistics/Metrics/` extending `AbstractMetric`: a `key()`, a `compute(Scope, MetricResults)`, and overrides only for what makes it unusual — `$scopes`, `dependsOn()`, `isCatalogWide()`, `isShelfScoped()`, `isEstimated()`.
2. Add it to `config/statistics.php`.
3. Add its key to `BACKEND_METRICS` in `resources/js/tests/services/statistics/surfaces.test.js`.

Read-derived metrics should start from `ReadInstanceQuery::forScope($scope)` so scope narrowing and the version join stay written once.

### Adding a surface

1. Write a config in `resources/js/services/statistics/surfaces/` — `scope`, and a `widgets` array of `{ id, widget, span, metrics, props }`. `visibleWhen(metrics)` hides a widget; `emptyWhen(metrics)` declares the whole surface empty.
2. Register it in `surfaces/index.js`.
3. Point a view at `<StatisticsGrid :surface="…" :scope-id="…" />`.

Labels live in the surface config, not the widget or the metric — the same metric may want different phrasing on a dense dashboard than on a full page. Widget interactions come back as `@widget-event="{ widgetId, name, payload }"`, so a widget stays generic and the view decides what a click means.

## Related

- Plan file: `/feature-plans/statistics-widgets.md` — future improvements and known limitations.
- `/documentation/books.md` — `Book` / `ReadInstance` schema, the rating-doubling mutator, discarded versions.
- `/documentation/lists.md` — the list domain whose statistics this now serves.
- `/documentation/read-history.md` — the other read-instance aggregation surface.
