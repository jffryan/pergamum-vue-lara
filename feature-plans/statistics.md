---
path: /feature-plans/
status: living
---

# Statistics

Superseded by `/feature-plans/statistics-widgets.md`, which shipped the scoped metric registry and the config-driven widget system. Everything this file used to track — the mixed casing, the raw Eloquent rows, `booksReadByYear` vs `uniqueBooksReadByYear`, undated reads, the missing rating and audio metrics, the doubled percentage queries, the direct axios import, the missing loading / error / empty states, the placeholder `UserDashboard`, the absent tests — is resolved there. Descriptive content lives in `/documentation/statistics.md`.

Two items outlived the refactor and moved to `/feature-plans/statistics-widgets.md` rather than being fixed:

- The `YEAR(date_read)` grouping still can't use an index. The `(user_id, date_read)` index exists; only the query rewrite is outstanding, and it is now a single method (`ReadInstanceQuery::groupedByYear()`) rather than one per metric.
- `totalBooks` and `newestBooks` still ignore user scoping. They now *declare* it via `meta.catalogWide`, so the contract is visible, but the decision to flip them belongs to `/feature-plans/books.md` and its book-ownership work.

Nothing else here is current. New statistics work belongs in `/feature-plans/statistics-widgets.md`.
