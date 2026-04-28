---
path: /feature-plans/
status: draft
---

# Backend test coverage

## Goal

Pergamum currently has zero backend tests — `tests/Feature/ExampleTest.php` and `tests/Unit/ExampleTest.php` are the stock Laravel placeholders, and `database/factories` only contains `UserFactory`. This plan establishes the strategy for building a backend test suite from scratch: what to test, in what order, with what tooling, and where the high-risk seams are.

The aim is not 100% coverage. It is enough coverage to (a) catch regressions in the user-scoped data conventions that are enforced inline per-query rather than by a policy or global scope, (b) lock down the auth surface so a future refactor doesn't silently break Sanctum-SPA cookie flow, and (c) give every feature module a feature test entry point so future work has a place to extend rather than a blank file.

## Approach

### Tooling already in place

PHPUnit 10, Mockery 1.x, FakerPHP, and Laravel's `RefreshDatabase` trait are installed via `require-dev`. `phpunit.xml` already wires `APP_ENV=testing`, `BCRYPT_ROUNDS=4`, array cache/session/mail, sync queue. The sqlite/in-memory env vars are commented out — see "Open questions" below.

No new dev dependencies are required for the first pass. Optional later: `pestphp/pest` for a friendlier DSL (skip for v1; stick with PHPUnit to keep the diff small), `spatie/laravel-ignition` (already present) for nicer failure output.

### Database strategy

Three viable options, decision deferred (open question):

1. **MySQL 8 in CI / dev (the production engine)** — most fidelity, slowest, requires a live `db` service. Best for migrations, JSON columns, full-text, anything MySQL-specific.
2. **SQLite `:memory:`** — fastest, no service required, but Pergamum uses MySQL-specific column types in places (auto-increment PK with custom name `id('book_id')` is portable; need to audit migrations for `unsignedBigInteger`, JSON, ENUM, fulltext indexes before committing).
3. **Hybrid** — sqlite for unit/service tests that don't touch the DB engine, MySQL for feature tests against the HTTP surface.

Recommendation: start with MySQL against the existing `db` container (`docker compose exec php php artisan test`) using `RefreshDatabase`. Revisit sqlite only if test runtime becomes painful. The migrations include custom PK column names (`book_id`, `author_id`, etc.) — verify those play nicely with whichever engine before locking in.

A dedicated `pergamum_testing` schema (or `DB_DATABASE` env override in `phpunit.xml`) keeps test data out of the dev database.

### Factory backlog

Every model needs a factory before its feature tests can be written. Build these first in `database/factories/`:

- `BookFactory` — title, slug, plus a state/helper to attach authors, genres, and at least one version.
- `AuthorFactory` — name + slug.
- `GenreFactory` — name.
- `FormatFactory` — name (audio / physical / ebook etc.; check seeded values).
- `VersionFactory` — `book_id`, `format_id`, `page_count`, `audio_runtime`, `nickname`. State `withReadInstances($count)`.
- `ReadInstanceFactory` — `version_id`, `user_id`, `rating`, `date_read`. State `forUser($user)`.
- `BookListFactory` — `user_id`, name, ordering.
- `ListItemFactory` — `list_id`, `version_id`, position.

Factories must use the custom PK columns (`book_id`, `author_id`, `version_id`, `user_id`, etc.) — Eloquent's `$primaryKey` declaration handles the model side, but factory `definition()` arrays must reference the right FK column names when seeding pivot rows (`book_author`, `book_genre`).

### Test base classes & helpers

Add a small set of shared helpers under `tests/`:

- `tests/TestCase.php` — already exists; extend to expose `actingAsUser(?User $user = null): User` that creates (via factory) and authenticates a user via `actingAs($user, 'sanctum')`. Every API test will use this.
- `tests/Feature/Concerns/CreatesBookGraph.php` (trait) — helpers like `bookWithVersion()`, `bookWithReads($readCount)` so tests don't re-stitch the M2M graph by hand.
- Optional `RefreshDatabase` is applied per-test-class, not globally, so service-only unit tests can skip it.

### Coverage layering

**Unit tests (`tests/Unit/`)** — pure PHP, no HTTP, no DB unless the unit genuinely needs it.

- `app/Services/StatisticsService` — math/aggregation logic; mock the query layer or run against a small seeded dataset.
- `app/Services/BookService`, `app/Services/AuthorService` — input transformation, slug generation, dedupe of authors-by-name.
- `app/Models/*` — date casts/formatters, the `User::lists()` relation, any accessors. Light touch; don't test framework behavior.
- `app/Policies/BookListPolicy` — direct unit tests of each ability against owner / non-owner / unauth scenarios. Cheap and high-value.

**Feature tests (`tests/Feature/`)** — full HTTP cycle through `auth:sanctum`, asserting status codes, response shape, and DB state.

Organize one folder per domain to mirror controllers:

```
tests/Feature/
  Auth/            login, register, logout, /api/user probe, CSRF preflight
  Books/           index, store, show-by-slug, update, destroy, completed/years, completed/{year}, addReadInstance
  Authors/         index/show-by-slug, getOrSetToBeCreatedAuthorsByName
  Genres/          resource CRUD
  Formats/         store, config endpoint
  Versions/        addNewVersion
  NewBook/         createOrGetBookByTitle, completeBookCreation (the multi-step flow)
  Lists/           resource CRUD, reorder, list-items add/destroy, BookListPolicy enforcement
  Statistics/      fetchUserStats happy path + empty-data path
  BulkUpload/      upload happy path, malformed payload, partial-failure handling
```

### Risk-ranked priorities (build order)

1. **Auth surface (highest risk).** Login (web route, CSRF preflight, session regenerate), register (validation + auto-login), logout, `/api/user` probe (200 vs 401). Locks the foundation every other test relies on. Cover the gotchas from `documentation/auth.md`: CSRF 419 without preflight, `Auth::login` + `regenerate()` after register, `email_verified_at` nullable but unused.
2. **User-scoping convention (security-critical).** A targeted set of tests that prove every user-scoped endpoint refuses to leak data across users. Pattern: create user A's books / reads / lists, authenticate as user B, assert empty-or-403. Endpoints to cover: `BookController@index`, `getBooksByYear`, `addReadInstance` (can't write a read for someone else's version-or-can-it?), `StatisticsController@fetchUserStats`, `ListController` resource set, `ListItemController`. This is the most likely place a future refactor silently regresses.
3. **`BookListPolicy`.** Unit tests + feature tests for the resource routes that invoke it. Owner can view/update/delete; non-owner gets 403; unauthenticated gets 401.
4. **Books domain end-to-end.** Resource CRUD plus the slug lookup (`/book/{slug}`), the year-browse endpoints, and `addReadInstance`. Covers the `Book → Version → ReadInstance` chain that the rest of the app reads from.
5. **Lists & list items.** Resource + reorder + nested items. The reorder endpoint (`PATCH /lists/{list}/reorder`) is the only ordering-mutating route in the API and warrants dedicated assertions about persisted order.
6. **New-book creation flow.** `createOrGetBookByTitle` then `completeBookCreation` is the multi-step path the SPA uses; test both the create-new and find-existing branches, and the author-dedupe behavior of `getOrSetToBeCreatedAuthorsByName`.
7. **Statistics.** Happy path with seeded reads across years, empty path (no reads), boundary cases (read with null `date_read`, read with rating, read without).
8. **Bulk upload.** Happy path, schema-mismatch payload, transactional rollback on partial failure (verify no orphaned books / authors / versions if any row blows up).
9. **Authors / Genres / Formats / Versions.** Lower priority — straightforward resource endpoints, but each gets at least one create + one read test so the file exists for future expansion.

### Conventions to enforce

- One assertion of HTTP status, one assertion of response shape (`assertJsonStructure` or `assertJsonPath`), one assertion of DB state (`assertDatabaseHas`) per happy-path test. Keeps signal high without snapshot bloat.
- Always reference the custom PK columns explicitly in `assertDatabaseHas` (`['book_id' => $book->book_id]`, not `['id' => …]`).
- Auth tests use real session cookies (call `/sanctum/csrf-cookie` then `/login`); every other feature test uses `actingAs($user, 'sanctum')` to skip the cookie dance.
- Service unit tests construct services directly with mocked dependencies; do not boot the full container when avoidable.

## Touches existing systems

- `phpunit.xml` — may need a `DB_CONNECTION`/`DB_DATABASE` override (or a `.env.testing`) once the DB strategy is decided.
- `database/factories/` — adding factories per model. Existing `UserFactory` should be reviewed for the `user_id` PK shape.
- `tests/TestCase.php` — extend with `actingAsUser` helper; do not break the existing `CreatesApplication` trait wiring.
- `compose.yml` — no changes anticipated; tests run via `docker compose exec php php artisan test`. CI will need its own DB service if/when CI is set up (no CI exists today — out of scope for this plan).
- Existing controllers / services / models — **read-only** for this plan. Tests must not require code changes; if a test reveals a bug, file it as a separate task rather than bundling the fix.

## Open questions

- **DB engine for tests** — MySQL (fidelity) vs sqlite `:memory:` (speed) vs hybrid. Decide before writing the first feature test; the choice changes the `phpunit.xml` env block and possibly some migration columns.
- **Pest vs PHPUnit** — Pest reads nicer for greenfield suites but adds a dependency and a learning curve. Default: stay on PHPUnit 10 (already installed). Revisit only if the suite grows past ~50 files and the verbosity becomes a real cost.
- **Snapshot testing for JSON responses** — `spatie/phpunit-snapshot-assertions` makes large-payload assertions cheaper but encourages over-broad assertions that break on cosmetic changes. Default: skip; use `assertJsonStructure` / `assertJsonPath` instead.
- **CI integration** — no CI is wired up today. Out of scope for this plan, but the test suite should be runnable in a single command (`php artisan test`) so wiring CI later is trivial.
- **Seeded reference data (Formats)** — `formats` is a small enumerated table that the SPA treats as config. Decide whether tests should seed the canonical set via a shared trait or have each test create its own — matters for `BulkUpload` and `Version` tests that resolve format names to IDs.
- **Bulk upload fixtures** — what file format does `BulkUploadController::upload` expect (CSV? JSON? form-data with file?). Need to read the controller before writing those tests; flagged here so the test author doesn't get blocked.
- **Test data isolation in MySQL** — `RefreshDatabase` wraps each test in a transaction, but if any code under test uses `DB::statement` for DDL (e.g. truncates) the transaction breaks. Audit services for that pattern before committing to `RefreshDatabase` over `DatabaseMigrations`.

---

## Future improvements

(populated when this plan flips to `living`)

## Known limitations

(populated when this plan flips to `living`)
