---
path: /documentation/
status: living
---

# App shell & navigation

## Scope

Covers the SPA's chrome and navigation glue: the Blade entrypoint (`home.blade.php`), the Vue mount (`app.js` → `App.vue`), the two nav components (`HeaderNav` and `SidebarNav`), the static "marketing" pages (`HomeView`, `AboutView`, `ErrorNotFoundView`), and how the per-domain route files compose into one router (`router/index.js`). Authentication state drives most of the conditional UI; that state is owned by `auth.md`. The admin entry point in the header is owned by `admin.md`.

## Summary

A single `home.blade.php` mounts `<div id="app">`, Vite injects the JS bundle, and `app.js` creates the Vue app, installs Pinia + the router, and mounts. `App.vue` is the one and only layout: a fixed `HeaderNav` on top, a `SidebarNav` (desktop static, mobile drawer) on the left for logged-in users, and `<RouterView>` for everything else. There are no nested layouts and no per-route shells — every view renders inside the same chrome. The router composes per-domain route files (`book-routes`, `author-routes`, `list-routes`, `admin-routes`) plus inline definitions for the cross-cutting routes (home, about, login, 404, dashboard, formats, genres, completed, statistics).

## How it's wired

### Backend

- **Blade entry**: `resources/views/home.blade.php` — minimal HTML shell, sets `<title>Pergamum</title>`, includes `@vite('resources/css/app.css')` and `@vite('resources/js/app.js')`, renders `<div id="app">`. Body class is `antialiased bg-zinc-50`.
- **SPA fallback**: `routes/web.php` defines `Route::get('/{vue_capture?}', fn() => view('home'))->where('vue_capture', '[\/\w\.-]*')`. Anything that's not an auth route or an `/api/*` route serves the Blade view, and the SPA takes over routing client-side. The regex constraint excludes most special characters, but the practical effect is "every navigable URL." See `auth.md` for why auth routes must be defined before this wildcard.

### Frontend

- **Bootstrap** (`resources/js/app.js`): `import './bootstrap'` runs `bootstrap.js` (axios + lodash globals; see `auth.md`), then `createApp(App)`, `app.use(createPinia())`, `app.use(router)`, `app.mount('#app')`. No global directives, no plugins beyond Pinia + Router.
- **Layout** (`resources/js/App.vue`): the only layout component. Structure:
  - `<HeaderNav>` is sticky at the top, full-width, dark slate background.
  - On `lg:` viewports, `<SidebarNav>` renders statically in a 10rem-wide column when `authStore.isLoggedIn`.
  - On smaller viewports, `<SidebarNav>` renders inside a slide-in drawer (translated off-screen by default) plus a backdrop. A hamburger button on `HeaderNav` emits `hamburger-click`, which `App.vue` handles by setting `drawerOpen = true`.
  - The drawer auto-closes on every route change via a `watch` on `$route`.
  - `<RouterView>` is given conditional left-padding (`lg:pl-40`) when the sidebar is present.
- **Header** (`components/navs/HeaderNav.vue`): logo / home link on the left, "Admin" (logged-in only, desktop only via `hidden lg:inline`) / "About" / "Logout" or "Login" / "Dashboard" on the right. Pure presentational; reads `useAuthStore` and emits `hamburger-click` on the menu button.
- **Sidebar** (`components/navs/SidebarNav.vue`): four hand-grouped sections (Library + Genres; New book + Bulk upload; Completed + Statistics; Lists), each as a `<li>` with a `<router-link>`. Static — no active-link styling, no collapsing, no badges. Routes are referenced by name (`library.index`, `genres.index`, `books.new`, `books.bulk-upload`, `completed.home`, `statistics`, `lists.index`).
- **Router composition** (`resources/js/router/index.js`):
  - Imports `bookRoutes`, `authorRoutes`, `listRoutes`, `adminRoutes` and spreads them into one flat `routes` array.
  - Inline routes: `home`, `about`, `login`, `404` (all `meta: { public: true }`), `dashboard`, `formats.show`, `genres.index`, `genres.show`, `completed.home`, `statistics`.
  - `scrollBehavior()` returns `{ top: 0 }` on every navigation.
  - `beforeEach` is the auth gate (see `auth.md`).
- **Static views**:
  - `HomeView.vue` — title + tagline + hard-coded version string. Public.
  - `AboutView.vue` — one paragraph of copy. Public.
  - `ErrorNotFoundView.vue` — large "ERROR 404 / Page not found" panel. Public, mounted at `/404`.
  - `UserDashboard.vue` — placeholder, the default post-login redirect target. Owned by `auth.md`.

## Non-obvious decisions and gotchas

- **There is exactly one layout.** `App.vue` is it; every view renders in the same shell. Adding a "no-chrome" surface (a print view, a fullscreen reader, a public landing page distinct from `/`) currently means `v-if`-ing the chrome out of `App.vue` based on `route.meta`, or refactoring to a layout-component pattern. The pattern doesn't exist yet.
- **No catch-all wildcard route.** The router has a `/404` route but nothing matches arbitrary unknown paths to it. An unknown URL like `/asdf` is served by Laravel's SPA fallback (the Blade view), the SPA boots, vue-router finds no match, and `<RouterView>` renders nothing — the user sees the chrome with an empty body. Add `{ path: '/:pathMatch(.*)*', redirect: { name: 'NotFound' } }` (or render `ErrorNotFoundView` directly) to fix.
- **`HomeView` hard-codes `v 0.1.1`.** The version string isn't pulled from `package.json` or anywhere else. Changing the version means editing this file. Either pull from `import.meta.env` (Vite injects `VITE_*` envs at build time) or accept that the home page is the version-of-record and keep them in sync manually.
- **Sidebar visibility is auth-gated, header is mostly auth-aware too.** `authStore.isLoggedIn` drives whether the sidebar renders at all and what the header right-side shows. Anonymous users see `Pergamum / About / Login` only. There's no anonymous landing page beyond `HomeView` itself; non-`/` public routes (`about`, `login`, `404`) all render *without* a sidebar even though the chrome behaves as though it's the same layout.
- **The sidebar list is hand-edited, not derived from routes.** Adding a new top-level destination requires editing `SidebarNav.vue` directly; nothing in the router exposes "should this appear in the sidebar." Same failure mode as `AdminHome` (see `admin.md`): forget the link, the route is reachable but invisible.
- **No active-link styling.** `<router-link>` provides `router-link-active` / `router-link-exact-active` classes by default, but no CSS targets them on the sidebar. The current page is indistinguishable from any other in the nav.
- **Mobile drawer auto-closes on every route change.** `App.vue` has a `watch: { $route() { this.drawerOpen = false; } }`. Convenient for the hamburger menu, but worth knowing if a future route-change-without-navigation pattern (modal-as-route) is added — the drawer will close on that too.
- **The admin link is the only "desktop-only" nav item.** `hidden lg:inline` on the admin `<router-link>` in `HeaderNav` means mobile users have no visible path into `/admin`. The page itself is reachable by URL. That's likely an oversight, not a deliberate restriction.
- **`<title>` is static.** It's hard-coded to `Pergamum` in the Blade. Per-route titles aren't set; the browser tab says the same thing on every page. A `route.meta.title` + small `beforeEach` to write `document.title` is the standard fix.
- **No global error boundary, no global toast / notification surface.** Errors surface per-view (each view does its own `try/catch` and renders an `AlertBox` or logs). A shell-level error boundary would catch render errors from any view; a toast queue would reduce the per-view boilerplate.
- **CSS is global from `app.css` + Tailwind utility classes inline.** No scoped layout styles in `App.vue` beyond two unused `.body-grid` / `.body-container` classes left in `<style scoped>`. Worth a cleanup pass.
- **The `ErrorNotFoundView` template's `<style>` block has commented-out CSS** for a `.droplet` element that doesn't exist — copy-pasted from elsewhere. Cosmetic; safe to delete.
- **No favicon and no meta tags beyond viewport / charset.** No `<meta name="description">`, no Open Graph tags, no Apple touch icon. Fine for an internal app; would need attention if it ever gets shared.
- **Per-domain route files are split, but cross-cutting routes are inline.** `book-routes.js`, `author-routes.js`, `list-routes.js`, `admin-routes.js` exist; genres / formats / completed / statistics are defined inline in `router/index.js` despite arguably warranting their own files. The split is by feature ownership, not by URL prefix — applied inconsistently.

## Usage notes

### Adding a new top-level destination

1. Define the route in the appropriate per-domain route file, or inline in `router/index.js` if it's cross-cutting.
2. If anonymous users should reach it, add `meta: { public: true }`.
3. Add a `<router-link :to="{ name: '<name>' }">` to `SidebarNav.vue` in the most appropriate group, or to `HeaderNav.vue` if it's a chrome-level item.
4. Test with the desktop sidebar visible *and* with the mobile drawer open — both render the same component but with different surrounding chrome.

### Adding a route that should not show chrome

There's no first-class way to do this today. Options:

- Branch in `App.vue` on `route.meta.noChrome` and render only `<RouterView>` when set. Cheapest path; doesn't generalize.
- Refactor to a layout-component pattern: each view (or each route) declares its layout component, and `App.vue` becomes a tiny resolver. Right answer if more than one or two no-chrome surfaces are coming.

### Handling unknown URLs

Today they render the chrome with an empty body. Until a catch-all is added, link to `/404` explicitly when you need a not-found surface (e.g. when a slug-based view fetches and 404s).

## Related

- Plan file: `/feature-plans/app-shell.md` — known limitations and future improvements.
- `/documentation/auth.md` — `authStore.isLoggedIn` drives most conditional chrome; the router guard and the SPA fallback in `routes/web.php` are documented there.
- `/documentation/admin.md` — the "Admin" entry point in `HeaderNav` and the discoverability problem shared with `SidebarNav`.
