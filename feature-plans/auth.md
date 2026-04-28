---
path: /feature-plans/
status: living
---

# Authentication & users

Tracks rough edges and follow-up work for the auth surface (`User`, `UserController`, `AuthStore`, the global router guard, Sanctum SPA config). Descriptive content lives in `/documentation/auth.md`. The `BookListPolicy` is owned by `/feature-plans/lists.md`; cross-cutting items link there.

## Known limitations

### Documentation drift

- **`CLAUDE.md` is wrong about auth.** It claims "Sanctum token-based" auth and that `bootstrap.js` "attaches the Sanctum token from AuthStore." Neither is true — auth is Sanctum SPA cookie/session and no token is ever issued. The CLAUDE.md line should be corrected as part of the next non-trivial CLAUDE.md edit.

### Authorization & scoping

- **No `UserPolicy`.** No user-targeted endpoints exist today (no profile read / update / delete, no admin user-management surface), so there's nothing to authorize. The moment any of those land, a policy is needed.
- **Per-feature user scoping is inline-only.** `where('user_id', auth()->id())` is replicated by every controller and service that touches user data. A query that forgets the predicate silently leaks across users. A global scope or trait (`BelongsToCurrentUser`) on `ReadInstance` and `BookList` would make the scope the default and require a deliberate opt-out for cross-user queries (admin reports).
- **No password-confirmation flow for sensitive actions.** Laravel's `password.confirm` middleware exists but isn't wired up. Future "delete my account" or "change my email" surfaces should require a recent re-auth.

### Session & cookie surface

- **`SANCTUM_STATEFUL_DOMAINS` default omits `localhost:8080`.** The docker `APP_HTTP_PORT` default is `8080` and the Sanctum default stateful list doesn't include it. Login appears to succeed but the next API call 401s. Either ship a `.env.example` with `SANCTUM_STATEFUL_DOMAINS=localhost:8080,localhost:5173` set, or document it in the README setup steps.
- **CORS is configured for same-origin only.** `allowed_origins: ['*']`, `supports_credentials: false`. A split-origin deploy needs both flipped (and `'*'` becomes illegal with credentials). Until then, the SPA must be served from the Laravel origin.
- **No session-expiry handling in the SPA.** When the session expires mid-use, the next API call comes back 401. `AuthStore.fetchUser` would treat that as "logged out" but it's never re-called after the initial probe — so the user sees per-feature errors (a 401 from `/api/lists`, etc.) until they navigate. Add an axios response interceptor that on 401 clears `AuthStore` and redirects to `/login?redirect=...`.
- **No "remember me" / persistent login.** `Auth::attempt` is called without the second-arg `$remember`. Closing the browser ends the session.

### Validation & request shape

- **No `FormRequest` for login / register / logout.** Validation is inline in the controller. Fine while there are three endpoints; gets unwieldy when password-reset / email-update / etc. land.
- **Email is not normalized.** `email` is validated `email` but not lowercased / trimmed. `Foo@Example.com` and `foo@example.com` are different rows because `email`'s unique index is case-sensitive in MySQL's default `utf8mb4_unicode_ci` only sometimes. Lowercase before insert, or set the column collation explicitly.
- **Password rules are minimal.** `min:8|confirmed`, no complexity, no breach check. Laravel's `Password::min(8)->mixedCase()->uncompromised()` would tighten this without much code.
- **No rate limiting on login or register.** The `api` middleware has `throttle:api`, but `web` doesn't. `POST /login` is unrate-limited and brute-forceable. Add `throttle:6,1` (Laravel's documented login throttle).
- **No CAPTCHA / bot deterrent on register.** Even with a UI form, the endpoint is open. Once registration is real, expect spam signups.

### Email verification & password reset

- **`MustVerifyEmail` is commented out.** The `email_verified_at` column exists, the cast is on, but the trait isn't applied and no `VerifyEmailController` is registered. No verification email is sent on register.
- **No password reset flow.** `Password::sendResetLink` / `ResetPasswordController` aren't wired up. Users who forget their password have no recovery path.
- **No email update endpoint.** A user can't change their email through the UI or API.

### Frontend & UX

- **No registration UI.** `POST /register` exists; `RegisterView.vue` and `UserRegisterForm.vue` do not. Self-serve sign-up requires building both, plus a route entry, plus a "Don't have an account?" link from `LoginView`.
- **No password-reset UI.** Same — can't add it before the backend exists.
- **No profile / settings view.** A user has no UI to see or change their name, email, or password.
- **`UserDashboard.vue` is a stub.** "Welcome to your Dashboard. This is a placeholder view. You're logged in!" The default redirect target after login is `/dashboard`, so every successful login lands here. Either fold the stats dashboard into it, build something useful, or change the default redirect to `/library` (or wherever the user actually wants to go).
- **`AuthStore.fetchUser` swallows all errors as "logged out."** A 500 or a network timeout on the auth probe sends the user to `/login` with no diagnostic. Distinguish 401 (genuinely unauthenticated) from other failures and surface a recoverable error UI for the latter.
- **Direct `axios` import in `AuthStore` and `UserLoginForm`.** Same layering violation called out in `/feature-plans/read-history.md` and `/feature-plans/statistics.md`. Add `api/AuthController.js` with `login`, `register`, `logout`, `fetchUser`, `csrfCookie`.
- **Login form has no loading state.** Click "Login" and the button stays clickable; a slow network produces double-submits.
- **No redirect-back support after register.** `register` doesn't honor a `?redirect=` query param the way `login` does.
- **No "redirect after logout" affordance.** `AuthStore.logout` always pushes to `home` and that's the only path.
- **Login error messages are server-prose strings.** `error.value = err.response?.data?.message || "Login failed"` — fine until the message changes server-side and the SPA copy drifts.

### Error handling

- **`UserController` doesn't catch.** A DB failure on `Auth::attempt` or `User::create` becomes a 500 with the dev stack trace. Wrap critical paths in a try/catch with structured errors.
- **`logout` will 401 if the session has already expired.** The `auth` middleware short-circuits with 401 before the controller runs; `AuthStore.logout` then propagates that as a thrown axios error. The store still clears local state because... it doesn't — the `await` rejects and the rest of the action never runs. Local state stays "logged in" while the server thinks the user is gone.

### Extensibility

- **No tests for any auth path.** Login (success / wrong password / missing fields), register (success / duplicate email / weak password / missing confirm), logout (success / unauthenticated), `/api/user` (authenticated / unauthenticated). Necessary before any structural work.
- **`HasApiTokens` is on `User` but unused.** If token-based auth is a future need (mobile app, third-party integrations), the trait is ready. Currently dead surface.
- **No multi-tenancy primitives.** Single-user-per-account today; no household / family sharing of a library. The `lists` relation is per-user, but the catalog (`books`, `authors`, `genres`, `formats`) is global. Splitting "my library" from "the catalog" is a much bigger change than just auth — flagged here because any work on it starts at the user model.
- **No SSO / OAuth.** No "Sign in with Google / Apple / GitHub." Likely never needed for a personal-library app, but worth noting if friends/family adoption ever becomes a goal.
- **Sanctum's tokens table migration is published** (`personal_access_tokens`) but no code creates or consumes tokens. Either remove the migration or actually wire up tokens.

## Future improvements

In rough priority order.

1. **Fix the CLAUDE.md auth description.** Replace the "Sanctum token-based" line with the SPA cookie/session reality. Quickest single-line correction in the repo.
2. **Add Feature tests** for login (success, wrong password, missing fields, throttling), register (success, duplicate email, validation), logout (success, unauthenticated), `/api/user` (authenticated, unauthenticated). Necessary before any structural change below.
3. **Document `SANCTUM_STATEFUL_DOMAINS=localhost:8080`** in `.env.example` and the README. Cheapest fix to the most common dev-setup confusion.
4. **Add `api/AuthController.js`** with `csrfCookie`, `login`, `register`, `logout`, `fetchUser`. Route `UserLoginForm` and `AuthStore` through it. Removes the direct axios imports.
5. **Add an axios response interceptor** that on 401 clears `AuthStore` and redirects to `/login?redirect=<current-path>`. Solves the "session expired mid-session" UX hole.
6. **Throttle `POST /login` and `POST /register`** with `throttle:6,1` (or stricter). Both are currently unrate-limited.
7. **Lowercase + trim emails** before persistence and on lookup. Prevents accidental dupes from `Foo@example.com` vs `foo@example.com`.
8. **Build the registration UI.** `RegisterView.vue` + `UserRegisterForm.vue` + a `/register` route entry + a link from `LoginView`. Endpoint already exists.
9. **Fix the logout failure path.** Even on a 401 from `/logout`, clear local `AuthStore` state and redirect — the server already thinks the user is gone.
10. **Add a loading state to the login button** and disable double-submits while a login is in flight.
11. **Decide what `/dashboard` should be.** Either fold `StatisticsDashboard` into `UserDashboard`, change the default post-login redirect to `/library`, or build a real home dashboard.
12. **Fold `password.confirm` into sensitive endpoints.** Wire the middleware up; require it for any future "change email" / "delete account" / "rotate password" surface.
13. **Add password-strength rules.** Switch register validation to `Password::min(8)->mixedCase()->uncompromised()`. Likely uncontroversial.
14. **Wire up email verification.** Apply `MustVerifyEmail` to `User`, register the verification routes, send the verification mail on register, gate write-paths behind `verified`. The `email_verified_at` column is already there.
15. **Wire up password reset.** Standard Laravel `Password::routes()` plus minimal SPA views (`ForgotPasswordView`, `ResetPasswordView`).
16. **Build a profile/settings view.** Read + update name and email; change password (with current-password confirm); delete account (with `password.confirm`).
17. **Add a `BelongsToCurrentUser` global scope (or trait)** for `ReadInstance` and `BookList`. Removes the inline `auth()->id()` predicate from every query and makes cross-user queries an explicit opt-out (`withoutGlobalScopes`). Coordinate with `/feature-plans/read-history.md`, `/feature-plans/statistics.md`, `/feature-plans/lists.md`.
18. **Add "remember me"** to login (second arg to `Auth::attempt`).
19. **Add `personal_access_tokens` use or remove the migration.** If a mobile / CLI / integration use case is on the roadmap, design the token issuance flow; otherwise drop the unused surface.
20. **Coordinate with future book ownership** (see `/feature-plans/books.md`). When books become user-owned, `auth()->id()` scoping has to extend to `Book::count()` and `Book::latest()` in `StatisticsService` (see `/feature-plans/statistics.md` item 13). The decision lives there; the user-model changes (a `User::books()` relation, a `books.user_id` column) live here.
