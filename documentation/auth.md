---
path: /documentation/
status: living
---

# Authentication & users

## Scope

Covers the full auth surface: the `User` model, login / logout / register endpoints in `routes/web.php`, the `auth:sanctum` middleware that gates every API route, the `AuthStore` Pinia store, the global router guard that enforces sign-in, and the CSRF / session machinery the SPA depends on. Per-feature user scoping (the `where('user_id', auth()->id())` convention used by books, lists, read history, and statistics) is referenced from those docs but defined here.

## Summary

Pergamum runs **Sanctum in SPA / cookie-session mode**, not token mode. The SPA is served same-origin from a Blade view (`home.blade.php`); login posts to `/login` (a web route, not `/api/...`), Laravel sets a session cookie, and every subsequent `/api/*` request is authenticated by `auth:sanctum` reading that session via `EnsureFrontendRequestsAreStateful`. There is no API token issued, no `Authorization: Bearer …` header, and `bootstrap.js` does not attach a token despite what `CLAUDE.md` says. The single user identity threads through every other feature: `auth()->id()` is the canonical scope key, and `users.user_id` is the custom primary key it returns.

## How it's wired

### Backend

- **Routes** (`routes/web.php`, all session-based):
  - `POST /login` → `UserController::login` — validates email + password, calls `Auth::attempt`, regenerates session, returns 200 / 401.
  - `POST /register` → `UserController::register` — validates name + unique email + confirmed 8-char password, hashes, creates user, logs them in, regenerates session.
  - `POST /logout` → `UserController::logout` — `auth` middleware required; calls `Auth::guard('web')->logout`, invalidates session, regenerates CSRF token.
  - The wildcard `GET /{vue_capture?}` SPA fallback is defined in the same file, **after** the auth routes (order matters — see gotchas).
- **Routes** (`routes/api.php`):
  - `GET /api/user` (inside the `auth:sanctum` group) — returns `$request->user()`, the canonical "who am I" probe used by `AuthStore::fetchUser`.
  - Every other API route is in the same `auth:sanctum` group; there are no public API routes.
- **Controllers**: `UserController` holds login / register / logout. Thin and self-contained; no service layer.
- **Models**: `User` (table `users`, **PK `user_id`** declared via `protected $primaryKey = "user_id"` and `$table->id('user_id')` in the migration). Uses `HasApiTokens` (provided by Sanctum but unused — no token endpoints exist), `HasFactory`, `Notifiable`. `password` and `remember_token` are hidden on serialization. The only relation defined is `lists(): HasMany` (to `BookList`); `readInstances` are reached only through books / versions, not directly off the user.
- **Policies / authorization**: no policy on the user model. Authorization across the app is split:
  - `BookListPolicy` is the only real policy (see `lists.md`).
  - Everything else relies on the convention `where('user_id', auth()->id())` enforced inline per query (read instances, statistics, year-browse). The middleware proves *who* the user is; per-feature controllers / services prove *what they can see*.
- **Migrations**: `2014_10_12_000000_create_users_table.php`. `id('user_id')` is a bigint auto-increment with a custom column name. `email` is unique. `email_verified_at` is nullable and is **not used anywhere** — no verification flow has been built.
- **Sanctum config** (`config/sanctum.php`):
  - `stateful` defaults include `localhost`, `localhost:3000`, `127.0.0.1`, `127.0.0.1:8000`, `::1` plus whatever `Sanctum::currentApplicationUrlWithPort()` returns. **`localhost:8080` (the docker `APP_HTTP_PORT` default) is not in this default list** — set `SANCTUM_STATEFUL_DOMAINS` in `.env` to add it, or stateful cookies won't be issued.
- **CORS** (`config/cors.php`): `paths: ['api/*', 'sanctum/csrf-cookie']`, `allowed_origins: ['*']`, **`supports_credentials: false`**. This is fine while the SPA is served same-origin from the Blade entry. The moment frontend and backend split origins (a separate Vite-served domain in production), `supports_credentials` must flip to true and `allowed_origins` must enumerate explicit hosts — wildcards aren't allowed with credentials.
- **Kernel** (`app/Http/Kernel.php`): the `api` middleware group is `EnsureFrontendRequestsAreStateful` → `throttle:api` → `SubstituteBindings`. The first one is what makes `auth:sanctum` accept the session cookie set by `/login`.

### Frontend

- **API layer**: **none.** No `api/AuthController.js` or `api/UserController.js` wrapper exists; both `UserLoginForm.vue` and `AuthStore` call axios directly. Same layering violation flagged in `read-history.md` and `statistics.md`.
- **Stores**: `resources/js/stores/AuthStore.js` (Pinia). State: `user` (the serialized User payload from `/api/user`, or `null`) and `authChecked` (set after the first probe so the router guard doesn't re-fetch on every navigation). Getter: `isLoggedIn` = `!!user`. Actions: `fetchUser()` (GET `/api/user`, swallows errors and sets `user = null`) and `logout()` (POST `/logout`, clears state, redirects to `home`).
- **Service**: none.
- **Routes**: defined inline in `resources/js/router/index.js`. The `login` route is `meta: { public: true }`; `home`, `about`, `404` are also public. Everything else is gated by the global guard:
  ```js
  router.beforeEach(async (to) => {
      const authStore = useAuthStore();
      if (!authStore.authChecked) await authStore.fetchUser();
      if (!to.meta.public && !authStore.isLoggedIn) return { name: "login" };
      if (to.name === "login" && authStore.isLoggedIn) return { name: "home" };
  });
  ```
- **Views**: `LoginView.vue` (a thin shell that renders `UserLoginForm`). **There is no `RegisterView.vue`** — the `POST /register` endpoint exists but no UI exposes it. No password-reset, no email-verification, no profile views either.
- **Components**: `components/auth/UserLoginForm.vue` — handles the login flow end-to-end. Calls `GET /sanctum/csrf-cookie` first to seat the CSRF cookie, then `POST /login`, then `AuthStore.fetchUser()`, then redirects to `route.query.redirect || '/dashboard'`.
- **Header / sidebar**: `components/navs/HeaderNav.vue` and `components/navs/SidebarNav.vue` both branch on `authStore.isLoggedIn` for showing nav, login, logout, and the sidebar shell.

## Non-obvious decisions and gotchas

- **It's Sanctum SPA, not Sanctum token.** `CLAUDE.md` describes "Sanctum token-based" auth and says `bootstrap.js` "attaches the Sanctum token from AuthStore." Neither is true — `bootstrap.js` only sets `X-Requested-With: XMLHttpRequest`, no token logic exists, and no token is ever issued. Authentication is session-cookie based via `EnsureFrontendRequestsAreStateful`. The `HasApiTokens` trait on `User` is dead surface.
- **Login lives in `routes/web.php`, not `routes/api.php`.** This is deliberate (and required) — Sanctum SPA login needs the `web` middleware group's session + CSRF stack, not the `api` group's stateless one. Anyone "moving auth into the API for consistency" will silently break login.
- **The CSRF cookie must be fetched before `/login`.** `UserLoginForm` does `axios.get('/sanctum/csrf-cookie')` first. If a future caller posts to `/login` without that priming GET, Laravel's CSRF middleware rejects it with a 419. The register form (when one is built) needs the same pre-flight.
- **`/api/user` is the canonical "am I logged in?" probe.** The router guard `awaits AuthStore.fetchUser()` once on first navigation; `authChecked` then prevents re-probing. Server-side, the route is just `function (Request $request) { return $request->user(); }` — it returns the full hidden-field-stripped `User` payload, not just `{ id, email }`.
- **Public routes are flagged via `meta: { public: true }`.** The guard's negation is `!to.meta.public && !isLoggedIn`. Forgetting the meta on a new route silently makes it auth-required — usually the right default, but a route a user must hit while logged out (a future password-reset link, for example) will redirect-loop into `/login` without it.
- **Logout is a `web` route, not API.** `AuthStore.logout()` posts to `/logout` (no `/api` prefix). It also requires the `auth` middleware (not `auth:sanctum`), which is the standard session guard. Calling `/api/logout` would 404.
- **Custom PK on `User`.** `users.user_id` is the column; `$primaryKey = "user_id"`. Sanctum's tokens / Eloquent relations cope (Sanctum stores `tokenable_id` polymorphically; Laravel's `Auth::loginUsingId` honors the model's `getKey()`), but **`auth()->id()` returns the value of `user_id`, not a column called `id`**. Joins from other tables to `users` must use `users.user_id`, not `users.id` — and `read_instances.user_id`, `lists.user_id`, etc. all FK to `users.user_id`.
- **`email_verified_at` is wired but unused.** The column exists, the cast exists, the trait `MustVerifyEmail` is *not* used (commented out in `User.php`), no verification middleware is registered, and no email is sent on register. A future verification rollout has the column ready but everything else to build.
- **Registration UI does not exist.** `POST /register` works (you can curl it), but nothing in the SPA renders a register form. Self-serve sign-up requires building `RegisterView` + `UserRegisterForm` + a route. Today users must be inserted via tinker / seeder or by hitting the endpoint manually.
- **`AuthStore.fetchUser` swallows errors.** Any non-200 from `/api/user` (401 unauthenticated, 419 CSRF mismatch, 500) is treated as "logged out" — `this.user = null`, `authChecked = true`. The user sees a redirect to `/login` with no diagnostic. Useful for the "not logged in" path, hostile to the "session expired mid-session" path.
- **`UserLoginForm` and `AuthStore` import axios directly.** Same layering violation as `read-history.md` and `statistics.md`. There is no `api/AuthController.js` wrapper. Until one exists, this and `AuthStore` are the two places to grep when the auth URLs or payload shapes change.
- **Sanctum stateful domains do not include the docker default port.** `SANCTUM_STATEFUL_DOMAINS` falls back to a list that has `localhost` and `localhost:3000` but **not `localhost:8080`** (the `APP_HTTP_PORT` default from `compose.yml`). Running the docker stack out of the box and hitting `http://localhost:8080`, login appears to succeed (200 from `/login`) but the next `/api/*` call comes back 401 because the session cookie wasn't issued as stateful. Set `SANCTUM_STATEFUL_DOMAINS=localhost:8080` in `.env`.
- **CORS is configured for same-origin only.** `supports_credentials: false` and `allowed_origins: ['*']`. The SPA is served from the same origin as the API today (Laravel renders `home.blade.php`, which loads the Vite bundle), so credentials-with-cookies works without CORS involvement. A split-origin deploy (Vercel-hosted SPA hitting an api.* backend, for example) requires flipping `supports_credentials` to true *and* enumerating origins (the spec forbids `*` with credentials).
- **`Auth::login($user)` after register, but the session must be regenerated.** `register` calls `$request->session()->regenerate()` immediately after `Auth::login` to prevent session fixation. Login does the same. Any new auth-state-changing action must do likewise.
- **The `web` group runs `VerifyCsrfToken`; the `api` group does not.** That's why `POST /login` needs the CSRF cookie pre-flight and `POST /api/...` does not — but the `api` group still gets session via `EnsureFrontendRequestsAreStateful`. It's a deliberate split, not an oversight.
- **`User::lists()` is the only direct user relation.** Read instances are user-scoped via the `user_id` column but there's no `User::readInstances()` HasMany. If a future surface needs "all reads by the current user" it should add the relation rather than fetching `ReadInstance::where('user_id', auth()->id())` from a controller.
- **The SPA fallback in `web.php` lives below the auth routes.** `Route::get('/{vue_capture?}', ...)->where(...)` matches anything; if it were defined first, `POST /login` and `POST /register` would still work (it's GET-only) but **`GET /login` would render the SPA Blade view rather than... whatever — it doesn't matter today because login is POST-only, but adding a GET handler later requires putting it above the wildcard.**

## Usage notes

### Login

`POST /login` (web route) with `{ email, password }`. Pre-flight `GET /sanctum/csrf-cookie` first to seat the XSRF cookie. 200 on success (response body `{ message: 'Logged in successfully' }`), 401 on bad credentials, 422 on validation failure. The session cookie is the auth credential going forward.

### Register

`POST /register` (web route) with `{ name, email, password, password_confirmation }`. 200 on success (`{ message: 'Registered and logged in' }`); the user is auto-logged-in and the session is regenerated. 422 on validation failure (uniqueness on email, 8-char min, confirmed match). No SPA UI exposes this today; callers must hit it directly.

### Logout

`POST /logout` (web route, requires `auth` middleware). Returns 200 (`{ message: 'Logged out' }`). `AuthStore.logout()` is the SPA path; it clears state and redirects to `home`.

### Who am I?

`GET /api/user` (inside `auth:sanctum`). Returns the serialized `User` (no password, no remember_token). 401 if unauthenticated. The router guard fetches this on first navigation; `AuthStore.user` holds the result.

### Scoping a query to the current user

The convention across this codebase is `->where('user_id', auth()->id())` on every read or write that's user-owned, applied inline per query. There is no "current user" macro on the query builder, no global scope, no policy that filters automatically. New features must thread this manually. See `read-history.md`, `statistics.md`, and `lists.md` for the three places this applies today.

## Related

- Plan file: `/feature-plans/auth.md` — known limitations and future improvements.
- `/documentation/lists.md` — the only feature with a real policy (`BookListPolicy`); contrast with the inline-scoping convention used everywhere else.
- `/documentation/read-history.md`, `/documentation/statistics.md` — both rely on `auth()->id()` user scoping defined here, and both share the direct-axios layering violation that the auth surface also exhibits.
- `/documentation/books.md` — defines the `User::lists()` relation's counterpart on `BookList`, plus the `read_instances.user_id` FK.
