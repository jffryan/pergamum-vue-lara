---
path: /feature-plans/
status: living
---

# Configurable statistics surfaces

Shipped. The backend metric registry (`app/Statistics/`) and the frontend widget registry plus `StatisticsGrid` are described in `/documentation/statistics.md`; this file tracks what's left.

This plan supersedes the original statistics plan, and moved list statistics server-side out of `/feature-plans/lists.md`.

## Known limitations

### Query shape and performance

- **`YEAR(date_read)` still can't use an index.** Every per-year metric groups by `YEAR(read_instances.date_read)` in `ReadInstanceQuery::groupedByYear()`. The `(user_id, date_read)` composite index exists and covers the user filter, but the grouping expression itself will scan. A range-bucketed rewrite is the fix; it now only has to happen in one method rather than in every metric.
- **No server-side caching.** Deliberate — the client store covers bouncing between `/dashboard` and `/statistics`, which is the case that actually bit. Revisit a short-TTL per-user cache invalidated on `ReadInstance` write when a surface exceeds roughly 15 metrics, or when one metric's query time becomes visible.
- **A full user surface issues one query per metric.** Twelve metrics is twelve round trips inside one request. Dependencies are computed once, but nothing batches independent metrics.

### Scope and semantics

- **`totalBooks` and `newestBooks` are catalog-wide, and staying that way.** They declare it (`meta.catalogWide`) and `WidgetShell` footnotes it. This was tracked as pending on book ownership; ownership was decided against (see `/documentation/books.md`), so the catalog-wide reading is now correct rather than provisional — the library is shared, and "how many books do we own" is a shared number. Only the *read* half of `percentageOfBooksRead` is per-user, which is the intended asymmetry.
- **`estimatedTotalPagesByYear` assumes one narration pace for everyone.** `config/statistics.php` holds a single `pagesPerAudioMinute` for all users and all books. A per-user setting, or a per-format one, would be more honest; the response already carries the factor, so a widget wouldn't change.
- **Estimates only understand audio.** `footnotes.js` builds its sentence from `meta.estimated[...].converted` and phrases it as audio. A second kind of estimate would need the provenance to name its own units.

### Frontend

- **No component tests for the widgets or the grid.** `@vue/test-utils` isn't installed — see `/feature-plans/frontend-tests.md`. `surfaces.test.js` covers configs, formatters and footnote generation; rendering is untested.
- **`BACKEND_METRICS` in `surfaces.test.js` is a hand-maintained mirror** of `config/statistics.php`. It catches a typo'd metric key in a surface config, but nothing catches the mirror itself drifting — a metric removed from the backend would still pass. Generating the fixture from the API, or from a shared constant, would close that.
- **`emptyWhen` is per-surface hand-written.** `listStatistics` declares `metrics.totalItems === 0`. Fine for one surface; a third or fourth wanting the same thing suggests the empty condition belongs to the scope rather than the surface.
- **Invalidation is call-site driven.** `UpdateBookReadInstance` calls `invalidateAll()` and `ListView` calls `invalidate("list", id)`. Any *new* write path has to remember to do the same. A store-level subscription, or invalidation inside the API layer, would make forgetting impossible.
- **Widget interactions are `select`-only.** `StatisticsGrid` re-emits a widget's `select` event as `widget-event`; a widget wanting a different event name needs the grid to know about it.

### Charts

- **No chart library.** `SeriesList` renders years as a list of spans. This was deliberately left out of the refactor — picking a library deserves its own comparison. The registry is what makes a later `barChart` a drop-in: same config entry, same metric key, different component, no backend change.

## Future improvements

In rough priority order.

1. **Author / genre / format scopes.** Each is a `ScopeResolver` case plus a surface config now — `/feature-plans/authors.md` item 10, `/feature-plans/genres.md` item 12, `/feature-plans/formats.md` item 11. They are the payoff this plan was built for; do them before anything else here.
2. **Charts.** Pick a library, add `barChart` / `lineChart` to the widget registry, swap the `widget:` line in the surface configs that want them. `SeriesList` stays for dense surfaces.
3. **Admin scope** — `/feature-plans/admin.md` item 21. Needs a scope-level authorization check (admin-only) that `ScopeResolver` can already accommodate; the shape is the same as the list scope's policy call.
4. **Range-bucketed year queries** in `ReadInstanceQuery::groupedByYear()`, replacing `YEAR()`. One method, every per-year metric benefits.
5. **Widget component tests** once `@vue/test-utils` lands.
6. **Batch independent metrics** into fewer queries — a single pass over `read_instances` could serve `readsByYear`, `uniqueBooksReadByYear`, `pagesReadByYear` and `audioRuntimeByYear` at once. Only worth it when the query count shows up in a profile.
7. **A header badge reading `totalBooksRead`** from the store without its own fetch. Cheap, and proof the cache generalizes past full surfaces.
8. **Per-user `pagesPerAudioMinute`**, once user settings exist anywhere.
9. **Eager-load `newestBooks`** if a richer card ever wants authors — today the metric returns `book_id`, `title`, `slug` and nothing more.
