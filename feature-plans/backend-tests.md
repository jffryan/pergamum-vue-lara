---
path: /feature-plans/
status: living
---

# Backend test coverage

Tracks rough edges and follow-up work for the backend test suite. Descriptive content and conventions live in `/documentation/backend-tests.md`.

## Future improvements

### Factory hygiene

- **`ReadInstanceFactory::definition()` leaks `Book` rows.** The definition calls `Version::factory()->create()` eagerly to source `book_id` / `version_id`, so even when callers override both via `forUser($u)->create(['book_id' => …, 'version_id' => …])`, an extra `Version` (and therefore an extra `Book`) is persisted. Surfaced while writing Priority 7: `tests/Feature/Statistics/StatisticsTest.php::test_percentage_of_books_read_uses_global_book_count` had to read `Book::count()` from the DB rather than assert against the four books explicitly created. Fix by switching `book_id` / `version_id` to closures that resolve only when the override isn't supplied — e.g. `'book_id' => fn (array $a) => isset($a['version_id']) ? Version::find($a['version_id'])->book_id : Book::factory()`. Once fixed, restore the literal-count assertion in that test.

## Known limitations

- **Coverage is breadth-first, not exhaustive.** The build order in the original plan prioritized one feature-test entry point per domain over deep coverage of any single domain. Most controllers have a happy path and a failure path; few have boundary-case sweeps. Future work should fill in per-domain depth as bugs are surfaced rather than upfront.
- **No CI integration.** The suite is runnable in a single command but nothing currently runs it automatically on push or PR. Wiring CI is out of scope for the initial test build-out and tracked elsewhere.
- **MySQL-only.** Tests run against the dev `db` service via `RefreshDatabase`. Sqlite-in-memory was considered for speed but not adopted; if test runtime becomes painful, revisiting this is the lever to pull. Any migration that uses MySQL-specific column types would need auditing first.
- **No snapshot testing.** Large JSON responses are asserted via `assertJsonStructure` / `assertJsonPath`, which requires writing each path explicitly. Trade-off accepted; snapshots discourage precise assertions and break on cosmetic changes.