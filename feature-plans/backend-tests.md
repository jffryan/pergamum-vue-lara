---
path: /feature-plans/
status: living
---

# Backend test coverage

Tracks rough edges and follow-up work for the backend test suite. Descriptive content and conventions live in `/documentation/backend-tests.md`.

## Future improvements

### Factory hygiene

- ~~**`ReadInstanceFactory::definition()` leaks `Book` rows.**~~ Fixed as prework for `/feature-plans/statistics-widgets.md`. `book_id` / `version_id` are now lazy closures that derive one from the other, so an override no longer persists a stray `Version` → `Book`. The literal-count assertion in `tests/Feature/Statistics/StatisticsTest.php::test_percentage_of_books_read_uses_global_book_count` is restored and is what guards the fix.

  Two constraints to preserve if that factory is edited again: `version_id` must stay declared **before** `book_id` (`expandAttributes()` resolves closures in array order, and the `version_id` callback distinguishes "caller supplied a book" from "caller supplied nothing" by testing whether `$attributes['book_id']` is still an unexpanded `Closure`), and the two values must always agree — `ReadInstance::booted()` throws a `DomainException` on a book/version mismatch, so they cannot be defaulted independently.

## Known limitations

- **Coverage is breadth-first, not exhaustive.** The build order in the original plan prioritized one feature-test entry point per domain over deep coverage of any single domain. Most controllers have a happy path and a failure path; few have boundary-case sweeps. Future work should fill in per-domain depth as bugs are surfaced rather than upfront.
- **No CI integration.** The suite is runnable in a single command but nothing currently runs it automatically on push or PR. Wiring CI is out of scope for the initial test build-out and tracked elsewhere.
- **MySQL-only.** Tests run against the dev `db` service via `RefreshDatabase`. Sqlite-in-memory was considered for speed but not adopted; if test runtime becomes painful, revisiting this is the lever to pull. Any migration that uses MySQL-specific column types would need auditing first.
- **No snapshot testing.** Large JSON responses are asserted via `assertJsonStructure` / `assertJsonPath`, which requires writing each path explicitly. Trade-off accepted; snapshots discourage precise assertions and break on cosmetic changes.