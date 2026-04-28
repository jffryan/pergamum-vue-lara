---
path: /feature-plans/
status: in-progress
---

# Frontend test coverage

## Goal

The SPA has a small starter Vitest suite — `tests/api/apiHelpers.test.js`, `tests/services/BookServices.test.js`, `tests/stores/BooksStore.test.js`, `tests/stores/NewBookStore.test.js`. That's it: four files covering one helper, one service, two stores. The 7 other Pinia stores, all 6 axios-wrapper API controllers, every util, every service-layer call beyond `addVersionToBookService`, and 100% of the component and view layer are untested.

This plan establishes the strategy for growing the suite from "starter" to "covers the load-bearing seams." The aim is not 100% coverage. It is enough coverage to (a) lock the data-flow contract `views → services/stores → api/<Domain>Controller → axios` so a future refactor of `apiHelpers.js` or a store's shape doesn't silently break consumers, (b) catch regressions in the validation / change-detection utilities (`utils/validators.js`, `utils/checkForChanges.js`) that run before any network call, and (c) give every store and api-controller a test file so future work has a place to extend rather than a blank file.

## Approach

### Tooling already in place

Vitest 3, Pinia 2, Vue 3, axios, lodash, papaparse, sortablejs are installed. The four existing tests use `vitest`'s `vi.mock` for module-level mocking and `setActivePinia(createPinia())` per-test for store isolation — keep that as the convention. ESLint is configured with airbnb-base; tests must pass lint.

**Missing pieces (must be added before component/view tests can be written):**

- `@vue/test-utils` — Vue's official component mounting helper. Required for any `mount()` / `shallowMount()` of `.vue` files.
- `@vitest/coverage-v8` (optional but cheap) — coverage reports via `vitest run --coverage`. Defer until the suite is large enough to need triage.
- `jsdom` or `happy-dom` as the Vitest test environment — required for component tests that touch the DOM. Default to `happy-dom` (faster, lighter) unless something needs full jsdom fidelity.
- A `vitest.config.js` (or `test` block in `vite.config.js`) — currently neither exists; Vitest is running on defaults. We need one to set the `test.environment`, register the `@` alias explicitly (see "Aliases" below), and globalize `describe`/`it`/`expect` if desired.

### Aliases

`CLAUDE.md` flags this and it bites here too: `@` resolves to `resources/js` for ESLint and (apparently) for Vitest, but **not** in `vite.config.js`. The existing tests import via `@/stores/BooksStore` and work — Vitest must be picking up the alias from somewhere (likely jsconfig / fallback). Before adding more tests, make this explicit by adding `resolve.alias` to `vite.config.js` (or to a new `vitest.config.js` that extends it) so every consumer — Vite build, Vite dev, Vitest, ESLint — agrees. Do this before writing component tests; mounting components pulls in deeper import chains where misalignment surfaces fast.

### Test environment & DB strategy equivalent

Frontend has no DB, but the equivalent decisions are **what to mock and at which layer**:

1. **Network** — never let real axios calls leak. Mock at `axios` itself for `apiHelpers.test.js`-style tests; mock at the `@/api/<Domain>Controller` boundary for store tests; mock at the service boundary for component tests. Each layer's tests should mock the layer immediately below it, not deeper. This mirrors the codebase's documented data flow and keeps each test diagnostic when it fails.
2. **Pinia** — `setActivePinia(createPinia())` in `beforeEach`. Tests that mount a component depending on multiple stores should use `createTestingPinia()` from `@pinia/testing` (add as devDependency when first needed) so actions can be stubbed with `vi.fn()` automatically.
3. **vue-router** — for component tests that read `$route` / `$router`, install a memory-history router instance per test; do not import the real `router/index.js` (it would pull every view). Build a minimal router with only the route(s) under test.
4. **localStorage / cookies** — `happy-dom` provides both. Tests that depend on the Sanctum XSRF cookie should set it via `document.cookie =` rather than mocking the whole DOM.

### Test file layout

Mirror source structure under `resources/js/tests/`, one folder per layer:

```
resources/js/tests/
  api/             one file per controller in api/ — happy + error path per method
  services/        BookServices.js (already started); add per-service file as services grow
  stores/          one file per store — state shape, getters, actions (mock api/ layer)
  utils/           validators.js, checkForChanges.js — pure-function tests, the cheapest wins
  components/      mirror components/ subfolders (auth/, books/, lists/, newBook/, navs/, ...)
  views/           one file per view exercised end-to-end with mocked stores
  router/          guard behavior (auth-required redirect, public-meta bypass)
```

### Coverage layering

**Utils (`tests/utils/`)** — pure JS, no Vue, no Pinia, no DOM. The cheapest, highest-confidence layer; build first.

- `utils/validators.js` — every exported validator, happy + each failure mode.
- `utils/checkForChanges.js` — diffing logic between original and edited entities. Edge cases: nested arrays, unchanged but reordered, added vs removed items.

**Api wrappers (`tests/api/`)** — one file per controller in `api/`. `apiHelpers.test.js` already covers `makeRequest` / `buildUrl`; extend the pattern to:

- `AuthorController.js`, `BookController.js`, `BulkUploadApi.js`, `GenresController.js`, `ListController.js`, `VersionController.js`.

For each method: assert the URL shape, HTTP verb, payload shape, and that errors propagate. Mock axios via `vi.mock("axios")`.

**Stores (`tests/stores/`)** — one file per Pinia store. `BooksStore` and `NewBookStore` exist; add:

- `AuthStore.js` — `fetchUser` happy / 401-swallow path, `logout` clears state.
- `AuthorsStore.js`, `ConfigStore.js`, `GenreStore.js`, `ListsStore.js`.

Pattern from existing tests: `vi.mock("@/api/<X>Controller")`, `setActivePinia` per test, assert state mutations and that the right API method was called with the right args.

**Services (`tests/services/`)** — `BookServices.js` is the only service today. Existing test covers `addVersionToBookService`; extend to the rest of its exports. As new services land (`AuthService.js`, `ListService.js`, etc. — flagged as a layering gap in `documentation/auth.md`, `read-history.md`, `statistics.md`), add their files alongside.

**Components (`tests/components/`)** — by risk, not by exhaustiveness:

1. Forms with validation: `UserLoginForm.vue` (CSRF preflight + login + redirect), `BookCreateEditForm.vue`, `NewBookProgressForm.vue`, the `newBook/*Input.vue` family (these own most of the user-facing validation surface).
2. Table rows + interactive components: `BookshelfTable.vue` + `BookTableRow.vue`, `VersionTable.vue` + `VersionTableRow.vue`, `ListItemsTable.vue` (sortablejs drag-reorder is the bug-prone seam — assert that reorder emits the right payload, mock sortablejs).
3. Nav/auth UI: `HeaderNav.vue` and `SidebarNav.vue` branching on `authStore.isLoggedIn`.

Skip pure-presentation components (svgs, `AlertBox.vue`, `PageLoadingIndicator.vue`) unless they grow logic.

**Views (`tests/views/`)** — one test per view that mounts it with mocked stores and asserts the right top-level orchestration: which stores it calls, which child components it renders, what it does on route param changes. Don't re-test child component internals here.

**Router (`tests/router/`)** — the global guard in `router/index.js` is small but security-relevant. Tests:

- Unauthenticated user navigating to a private route is redirected to `login` with `query.redirect` preserved.
- Authenticated user navigating to `login` is redirected to `home`.
- `meta: { public: true }` bypasses the guard.
- `authChecked` is set after first probe and prevents re-fetch on subsequent navigations.

### Risk-ranked priorities (build order)

1. **Utils** (`validators.js`, `checkForChanges.js`). Pure functions, no setup, immediate ROI.
2. **Api wrappers** — finish the `tests/api/` set so every URL/payload contract is locked. Cheap and prevents the "renamed an endpoint, forgot one caller" class of bug.
3. **Stores** — finish the `tests/stores/` set. `AuthStore` first (security-relevant); `ListsStore` second (most complex state — items, ordering, current list).
4. **Services** — extend `BookServices` coverage; add new service test files as the layer grows.
5. **Router guard** — small surface, high security value.
6. **Forms** — `UserLoginForm` first (auth path), then the new-book and edit-book forms. These are where validation regressions hurt the user directly.
7. **Sortable lists** — `ListItemsTable` reorder, drag-and-drop interaction, assert payload to `ListController.reorder`.
8. **Views** — orchestration tests after the layers below are solid; don't bother before.
9. **Nav/auth UI** — light coverage; mostly assertions about what renders when logged in vs out.

### Conventions to enforce

- Mock the layer immediately below; do not mock deeper. Store tests mock `@/api/*`, not axios. Component tests mock services/stores, not axios.
- One test = one behavior. The existing `BooksStore.test.js` style (small, focused `it` blocks with section comments) is the model.
- Component tests use `mount` only when DOM behavior is asserted; use `shallowMount` otherwise to keep tests resilient to child changes.
- Never import the real `router/index.js` from a component test — build a minimal in-memory router. The real one pulls every view module and slows the suite.
- Tests must pass ESLint (airbnb-base + vue3-essential). The existing files do; keep that bar.
- File naming: `<Subject>.test.js`, mirroring the path of the subject under `resources/js/`.

## Touches existing systems

- `vite.config.js` — needs an explicit `resolve.alias` for `@` (currently missing); optionally a `test:` block, or a separate `vitest.config.js` extending it.
- `package.json` — add `@vue/test-utils`, `happy-dom`, `@pinia/testing` as devDependencies. Optionally `@vitest/coverage-v8`.
- `resources/js/tests/` — adding `utils/`, `components/`, `views/`, `router/` subfolders alongside existing `api/`, `services/`, `stores/`.
- Existing tests (`BooksStore.test.js`, `NewBookStore.test.js`, `BookServices.test.js`, `apiHelpers.test.js`) — leave alone. The conventions they establish are the ones this plan extends.
- Existing source — **read-only** for this plan. If a test reveals a bug, file it as a separate task rather than bundling the fix.

## Open questions

- **Test environment** — `happy-dom` (default recommendation) vs `jsdom`. happy-dom is faster but has occasional API gaps; jsdom is the safe choice if a component depends on something obscure (canvas, IntersectionObserver, etc.). Decide before writing the first component test.
- **`@pinia/testing` adoption** — adds a dependency. Worth it once stores are mocked from component tests in volume; skip while only direct store unit tests exist.
- **Coverage tooling** — `@vitest/coverage-v8` is one line of config, but enforcing a coverage threshold on a greenfield suite is counterproductive. Default: install but don't enforce; revisit when the suite is mature.
- **Pest-equivalent DSL** — none needed; Vitest's `describe`/`it` is already terse. No analog to the backend "Pest vs PHPUnit" question.
- **CI integration** — no CI is wired up today (same as backend plan). Out of scope; suite must remain runnable via `npm test`.
- **Snapshot testing** — Vitest supports it via `toMatchSnapshot()`. Same risks as on the backend (over-broad assertions, cosmetic-change churn). Default: skip; assert on specific properties / rendered text instead.
- **MSW (Mock Service Worker)** — an alternative to per-test axios mocks; intercepts at the network layer and gives one shared "fake API" across the suite. More setup, more fidelity. Default: stick with `vi.mock` for now; revisit if axios-mocking duplication grows painful.
- **Component testing scope creep** — the `newBook/*Input.vue` family is 8 files of overlapping form logic. Worth deciding whether to test each in isolation or to test the parent (`NewBookProgressForm`) end-to-end and let the children ride along. Lean toward the parent test + targeted child tests for the two or three with non-trivial validation.
- **Sortablejs in tests** — drag-and-drop isn't exercisable from happy-dom. Mock `sortablejs-vue3`'s emitted `update` event rather than simulating real drags; assert the resulting `ListController.reorder` call.

---

## Future improvements

(populated when this plan flips to `living`)

## Known limitations

(populated when this plan flips to `living`)
