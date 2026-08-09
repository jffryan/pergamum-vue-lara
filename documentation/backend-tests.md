---
path: /documentation/
status: living
---

# Backend tests

## Scope

Instructions for agents writing or extending Pergamum's backend test suite (PHP / PHPUnit).

**The suite is breadth-first by design.** It was built to give every domain one feature-test entry point rather than to cover any single domain exhaustively, so most controllers have a happy path and a failure path and few have boundary sweeps. That is the intended shape, not a backlog: depth gets added where a bug is actually surfaced, and the bug goes in the relevant plan file first (see Cardinal rules below). Don't read a thin directory as an invitation to write speculative boundary cases.

## When tests are in scope

Write or update tests when:

- Adding a new endpoint, controller method, service method, or policy.
- Modifying user-scoping behavior, slug generation, or any of the dual-attached / mutated columns.
- Changing eager-load shapes that views depend on.
- Touching the auth surface (login, register, logout, `/api/user`).

Do not write tests for purely cosmetic changes, comment edits, or dependency bumps that don't change behavior — same threshold as the changelog.

## Cardinal rules

These are the conventions that distinguish "tests for Pergamum" from "tests in general." Read these before writing or extending any test.

### Tests do not modify application code

A test exists to pin behavior, not to fix it. If a test reveals a bug in the application:

- **Legitimate bug** (the code is wrong, the test catches it): leave the test failing. Add an entry to the corresponding `/feature-plans/<feature>.md` under "Known limitations" describing the bug, with a reference to the failing test by file and method name. If no plan file exists for that feature, create one — frontmatter `status: living`, body containing only the `## Future improvements` and `## Known limitations` sections per `/feature-plans/README.md`.
- **Oddity, not a bug** (the code does something quirky but intentional, or the surprise is acceptable): write the test to pass against the actual behavior, and log the oddity as a future-improvement entry in the same plan file. The test pins what the code does today; the plan file records what we'd rather it did.
- **Never** "fix" the application code as part of a test PR. Even if the fix is one line. File it as a separate task. The PR description should call out the bug and link to the plan-file entry.

### Plan files are the bug log

`/feature-plans/<feature>.md` is the canonical place to record any rough edge surfaced while testing — wrong behavior, surprising behavior, missing validation, leaky abstractions, performance hazards. The plan files for shipped features already follow this pattern; new entries land in the same shape.

When adding to a plan, match the existing structure: a heading per category (Authorization, Validation, Data integrity, Performance, etc.), a bullet per concrete issue, one paragraph each. Reference the test that surfaced it.

### Tests must run via the existing tooling

`docker compose exec php php artisan test` (or `vendor/bin/phpunit` for a single file) is the only entry point. Do not introduce wrappers, scripts, or alternate runners. If a test needs new tooling, raise it as an open question in the plan file before adding it.

## Suite layout

```
tests/
  TestCase.php                  shared base; defines actingAsUser()
  Feature/
    Auth/                       login, register, logout, /api/user probe
    Books/                      resource CRUD, slug lookup, completed-by-year, read-instance
    Authors/                    resource smoke + slug lookup
    Genres/                     resource smoke
    Formats/                    resource + config endpoint
    Versions/                   addNewVersion
    Lists/                      resource CRUD, reorder, item add/destroy
    ListItems/                  if separated; otherwise nested under Lists/
    NewBook/                    multi-step creation flow
    Statistics/                 metric behavior, registry contract, list scope
    BulkUpload/                 happy path, malformed payload, partial failure
    UserScoping/                cross-user isolation tests (one file per domain)
    Concerns/                   shared traits (e.g. CreatesBookGraph)
  Unit/
    Policies/                   BookListPolicyTest etc.
    Services/                   BookService, AuthorService
```

One file per controller method group is fine; one file per controller is preferred when methods share fixtures. Use the existing files as templates — they encode the conventions below.

## Writing a feature test

### Authentication

Every API test (anything routed through `auth:sanctum`) authenticates via `$this->actingAsUser()` from `tests/TestCase.php`. Returns the `User` it created so the test can use it as the owner. Do not call `Sanctum::actingAs` directly — go through the helper so any future change to the auth shape is one edit.

Auth-surface tests (login, register, logout) are the exception: they exercise the real cookie / token flow and must not use `actingAsUser()`. Hit `/sanctum/csrf-cookie` first, then `/login` with the CSRF token, then assert the session is established.

### Custom primary keys

Models use `book_id`, `author_id`, `version_id`, `read_instance_id`, `list_id`, `list_item_id`, `user_id`, etc. — never plain `id`. This affects:

- `assertDatabaseHas` / `assertDatabaseMissing`: pass `['book_id' => $book->book_id]`, never `['id' => …]`.
- Factory overrides: `Book::factory()->create(['book_id' => 99])` works; `['id' => 99]` silently fails.
- JSON path assertions: response payloads use the custom keys (`$response->assertJsonPath('books.0.book_id', $book->book_id)`).

If a test uses `id` anywhere, it's wrong. Search-and-replace before opening the PR.

### User scoping

Most read endpoints scope by `auth()->id()` inline rather than via a global scope or policy. The `UserScoping/` folder pins this convention: for each domain, create user A's data, authenticate as user B, assert the response is empty (or 403 / 404 for direct access). When adding a new user-scoped endpoint, add a corresponding scoping test.

Patterns to assert:

- Index endpoints return only the authenticated user's rows.
- Show endpoints return 403 / 404 for other users' resources, never the row itself.
- Write endpoints (`addReadInstance`, list mutations) cannot create rows attributed to another user even when the payload tries.

### Assertions per happy-path test

Three assertions, in this order: status code, response shape, database state.

```php
$response = $this->postJson('/api/lists', ['name' => 'To read']);

$response->assertStatus(201);
$response->assertJsonStructure(['list' => ['list_id', 'name', 'slug', 'items']]);
$this->assertDatabaseHas('lists', ['user_id' => $user->user_id, 'name' => 'To read']);
```

Resist the urge to add fourth and fifth assertions for adjacent fields. If the response shape is large, `assertJsonStructure` is enough; reach for `assertJsonPath` only when a specific value matters semantically. Snapshot testing is not used in this suite — it makes refactors painful and discourages the precise assertions above.

### Failure-path tests

Every endpoint with validation or authorization gets at least one failure-path test alongside the happy path: missing required field, wrong owner, wrong shape. One per failure mode is enough — the goal is the behavior is pinned, not exhaustive enumeration.

### Database engine

Tests run against MySQL via the `db` service in `compose.yml`. `RefreshDatabase` wraps each test in a transaction. Three consequences:

- Any code under test that issues DDL (`DB::statement('TRUNCATE …')`, schema changes) breaks the surrounding transaction. Such code should not exist in the request path; if a test surfaces it, log it in the relevant plan file as a Known Limitation and use `DatabaseMigrations` for that single test class as a workaround.
- **The same applies to tests that issue DDL themselves,** which migration tests must. MySQL implicitly commits on DDL, ending the transaction `RefreshDatabase` opened — so neither the schema change nor any row written after it can be rolled back, and the leak lands on the *next* test in the process as a table missing an index. A test that drops or adds schema has to put it back by hand in `tearDown()`. `AddUniqueIndexToGenresNameTest` is the worked example.
- Tests cannot rely on auto-increment values being deterministic across runs. Use the IDs returned by factories, never literals.

A dedicated `pergamum_testing` database (or `DB_DATABASE` override in `phpunit.xml` / `.env.testing`) keeps test runs out of the dev DB.

**MySQL, not sqlite-in-memory — deliberately.** Sqlite would be faster, and it was considered and rejected: several behaviours under test are properties of the *connection*, not of Eloquent. Genre name matching is case-insensitive only because the collation is `utf8mb4_unicode_ci`, the unique index on `genres.name` inherits that same insensitivity, and `GenreController::index` orders with a MySQL-specific `REGEXP`. On sqlite those would silently pass for the wrong reason or fail for no reason. If suite runtime ever becomes painful this is still the lever to pull, but every MySQL-specific column type and expression needs auditing first.

## Factories

`database/factories/` has a factory per domain model. Conventions:

- The `definition()` returns a row that can be inserted standalone — every required FK is resolved (lazily where possible).
- States cover common shapes: `BookFactory::withVersion()`, `ReadInstanceFactory::forUser($u)`, etc. Add a state when two or more tests reproduce the same setup.
- Pivot rows (`book_author`, `book_genre`) are attached via factory states or `attach()` calls in test setup, not in `definition()` — `definition()` must not create related rows the caller didn't ask for.
- Slug-bearing factories generate uniqueness-safe slugs (suffix with the faker unique number) so factory bursts don't collide on unique indexes.

Refer to `FormatFactory` for the canonical-set pattern (random pick from a known list with a slug suffix). Refer to `BookFactory` for the with-relations pattern.

When a factory leaks rows that callers can't override, the workaround is to assert against `Model::count()` rather than literal counts, and to log the leak in the plan file. Do not change the factory's `definition()` to fix it as part of an unrelated test PR — that's a code change, governed by the cardinal rules above.

### `ReadInstanceFactory` has two constraints that are easy to break

`book_id` and `version_id` are lazy closures that derive one from the other, so overriding either no longer persists a stray `Version` → `Book`. If you edit that factory, preserve both of these or the leak comes back silently:

- **`version_id` must stay declared *before* `book_id`.** `expandAttributes()` resolves closures in array order, and the `version_id` callback distinguishes "caller supplied a book" from "caller supplied nothing" by testing whether `$attributes['book_id']` is still an unexpanded `Closure`.
- **The two values must always agree.** `ReadInstance::booted()` throws a `\DomainException` on a book/version mismatch, so they cannot be defaulted independently.

`StatisticsTest::test_percentage_of_books_read_uses_global_book_count` asserts a literal count and is what guards this.

## Unit tests

`tests/Unit/` holds tests that don't need the HTTP stack. Two categories:

- **Policies** — `BookListPolicyTest` is the template. Each ability (`viewAny`, `view`, `create`, `update`, `delete`) gets at least one positive and one negative case.
- **Services** — construct the service directly, mock dependencies with Mockery, assert on the return. Use a real DB only when the logic genuinely depends on SQL behavior — statistics metrics are the standing example, which is why they are Feature tests against the endpoint rather than unit tests.

Model accessor / mutator tests live in `tests/Unit/Models/` if they're worth pinning (the rating-doubling mutator is a good candidate). Skip tests for trivial framework behavior.

## Adding a new test file

1. Pick the directory by domain (matching the controller).
2. Copy the structure of the closest existing file in that directory.
3. Use `actingAsUser()` for setup unless testing the auth surface itself.
4. Cover at least one happy path and one failure path.
5. Run the file in isolation (`vendor/bin/phpunit tests/Feature/Lists/ListsCrudTest.php`) before running the whole suite.
6. If a test fails because of an application bug, follow the cardinal rules: log it in the plan file, do not fix it inline.

## Continuous integration

`.github/workflows/ci.yml` runs on every push to `main` and on every pull request, as two independent jobs:

- **backend** — `vendor/bin/pint --test`, then `vendor/bin/phpunit --no-coverage` against a `mysql:8` service container. `phpunit.xml` already forces `DB_DATABASE=pergamum_testing`, so the workflow only supplies host and credentials.
- **frontend** — `npx eslint --ext .js,.vue resources/js`, then `npm run test:run`, then `npm run build`.

Two things to keep in step when changing either side:

- **The MySQL service version and the collation must match `config/database.php`.** See the engine rationale above — a CI database on a different collation would turn the genre tests into false passes.
- **`--ext .js,.vue` is not optional.** Without it eslint reads only `.js`, every `.vue` file passes because it was never opened, and CI goes green over a broken component.

Coverage is disabled in CI (`--no-coverage`) — the reports configured in `phpunit.xml` are a local triage tool, and generating them on every push buys nothing.

## Related

- `/documentation/books.md`, `/documentation/lists.md` — the conventions tested here (custom PKs, dual-attached read instances, rating doubling, user scoping) are defined in these docs.
- `/feature-plans/frontend-tests.md` — the SPA-side suite, which shares this workflow file.
- `/feature-plans/README.md` — lifecycle for plan files, which is where bugs surfaced by tests get logged.
- `CLAUDE.md` — top-level command reference (`php artisan test`, `vendor/bin/phpunit`, `vendor/bin/pint`).