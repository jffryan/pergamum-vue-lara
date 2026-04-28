---
path: /feature-plans/
status: living
---

# App shell & navigation

Tracks rough edges and follow-up work for the SPA chrome (`App.vue`, `HeaderNav`, `SidebarNav`, the static views, and the router composition). Descriptive content lives in `/documentation/app-shell.md`. Auth-driven conditional UI is owned by `/feature-plans/auth.md`; the admin entry point is owned by `/feature-plans/admin.md`. This is a "polish + discoverability" surface, not a domain feature — most items are small but visible.

## Known limitations

### Routing & not-found

- **No catch-all wildcard route.** Unknown URLs render the chrome with an empty body — Laravel's SPA fallback serves the Blade view, the SPA boots, vue-router matches nothing, `<RouterView>` renders nothing. The `/404` route exists but nothing routes traffic to it.
- **`/404` is reachable by direct navigation only.** No view in the codebase pushes to it on a 404 from the API. Slug-based detail views (book, author, format) that get a 404 from the backend either render an error message inline or do nothing — none of them push to `NotFound`.
- **Per-domain route files are split inconsistently.** `book-routes`, `author-routes`, `list-routes`, `admin-routes` exist; genres / formats / completed / statistics are defined inline in `router/index.js`. The split is by feature, applied unevenly.
- **No `scrollBehavior` for back-navigation.** `scrollBehavior` always returns `{ top: 0 }`. Hitting back from a deep scroll on a long bookshelf scrolls to top instead of restoring the previous position. Vue Router's `savedPosition` arg is ignored.

### Chrome & layout

- **There is exactly one layout.** Every view renders inside `App.vue`'s shell. No print view, no fullscreen mode, no public landing distinct from the chromed `/`. Adding a no-chrome surface today means `v-if`-ing the chrome out of `App.vue` based on `route.meta`.
- **Anonymous users see a half-shell.** Public routes (`/`, `/about`, `/login`, `/404`) render the header but no sidebar (because `authStore.isLoggedIn` is false). The header omits the right-side action set too. Functional, but visually unbalanced.
- **No global error boundary.** A render error from any view propagates up; the user gets a blank page. A shell-level boundary that renders a friendly "something broke" panel would catch this.
- **No global toast / notification surface.** Every view does its own `try/catch` and renders an `AlertBox`. A shared toast queue would cut a lot of repetition and let cross-view feedback (e.g. "saved on the previous page") work.
- **`App.vue`'s `<style scoped>` carries dead `.body-grid` / `.body-container` classes** never referenced in the template. Cosmetic cleanup.

### Navigation discoverability

- **`SidebarNav` is hand-edited, not derived from routes.** Adding a top-level destination means editing the sidebar component directly. Same failure mode flagged in `/feature-plans/admin.md`: forgot the link, route is reachable but invisible.
- **No active-link styling.** Vue Router applies `router-link-active` / `router-link-exact-active` classes by default; no CSS targets them on the sidebar or header. The current page is indistinguishable in the nav.
- **`HeaderNav`'s "Admin" link is `hidden lg:inline`.** Mobile users have no path into `/admin` short of typing the URL. Likely an oversight, not a deliberate restriction.
- **The "Dashboard" button in `HeaderNav` is the most prominent right-side action,** but `/dashboard` is `UserDashboard.vue` — a placeholder that says "You're logged in!". The most prominent CTA leads to the most useless surface.
- **No breadcrumbs anywhere.** Deep navigation (book → version → add read history; list → statistics) has no positional context.
- **No collapsing on the sidebar, no sub-grouping beyond the four hard-coded groups.** As the surface grows (each tier of the documentation backfill plan suggests new surfaces eventually), the sidebar will need a real structure.

### Page metadata

- **`<title>` is static.** Hard-coded `Pergamum` in the Blade. Browser tab says the same on every page; bookmarks are indistinguishable.
- **No meta description, no Open Graph, no favicon.** Fine internally; bad if anything is ever shared.
- **Hard-coded version string in `HomeView`.** `v 0.1.0` literal text. Editing the version means editing the view; no single source of truth shared with `package.json` or `composer.json`.

### Mobile & responsive

- **The mobile drawer auto-closes on every route change.** Convenient today; will surprise a future "modal-as-route" pattern that fires `$route` watchers without the user actually leaving.
- **No swipe-to-close on the drawer.** Tap-the-backdrop works; swipe doesn't.
- **`HomeView` and `ErrorNotFoundView` use `w-1/2` for their content panel** with no `lg:` breakpoint adjustment — content is cramped on narrow screens, sparse on wide ones.

### Accessibility

- **Hamburger button has `aria-label="Open menu"`** but the drawer doesn't announce when it opens — no `role="dialog"`, no focus trap, no `aria-modal`, no return-focus-to-trigger on close.
- **No skip-to-content link.** Keyboard users tab through the entire header on every page.
- **Color contrast in `HeaderNav`** uses `text-slate-400` on `bg-slate-900` for inactive links — passes WCAG AA at 4.5:1 marginally; `text-slate-500` would not. Worth a deliberate audit.
- **No focus-visible styles** beyond browser defaults. Slate-on-slate buttons have no visible focus ring on dark backgrounds.

### Static views

- **`HomeView`, `AboutView`, `ErrorNotFoundView` all carry an unused `.droplet` `<style scoped>` block** for an `<img>` they don't render. Copy-paste residue.
- **`AboutView` is a single paragraph of generic copy.** No version, no link to a repo, no contact, no changelog reference.
- **`HomeView` is sparse.** For a logged-out user it's a tagline; for a logged-in user it's the same tagline. No "your last read", no "jump back into list X", no "what's new."

### Tests

- **No SPA tests for the router guard, the SPA fallback, the not-found behavior, or the layout's auth-conditional rendering.** All of this is the kind of thing that breaks silently.

## Future improvements

In rough priority order. Cheap wins are deliberately front-loaded; this surface improves a lot from a few small fixes.

### Cheap, high-leverage

1. **Add a catch-all wildcard route.** `{ path: '/:pathMatch(.*)*', name: 'NotFound', component: () => import('@/views/ErrorNotFoundView.vue'), meta: { public: true } }`. Replaces the current "blank body for unknown URLs" with a real 404 page. Single-line plus a route registration.
2. **Add active-link styling.** Tailwind class on `router-link-active` (or use the `<router-link>` `active-class` prop) for the sidebar and header. Visible-state-of-current-page for free.
3. **Restore scroll position on back-navigation.** `scrollBehavior(to, from, savedPosition) { return savedPosition ?? { top: 0 }; }`. Two-line change; meaningful UX win on long lists.
4. **Per-route `<title>` via `route.meta.title`.** Add a `router.afterEach` that sets `document.title = ${meta.title} · Pergamum` (fall back to plain "Pergamum"). Annotate routes as you go; titles can land incrementally.
5. **Show the admin link on mobile** — drop `hidden lg:inline` from the admin `<router-link>` in `HeaderNav`, or move it into the sidebar's "Library + Genres" / "Actions" group.
6. **Delete the dead `.droplet` `<style>` blocks** from `HomeView`, `AboutView`, `ErrorNotFoundView`. Delete the unused `.body-grid` / `.body-container` from `App.vue`. Unblocks any future scoped-style work in those files.
7. **Pull the version string from a single source.** Either expose `import.meta.env.VITE_APP_VERSION` (set in CI from `package.json`) or read `package.json` at build time via Vite. Replace the `v 0.1.0` literal in `HomeView`.

### Discoverability

8. **Route-driven sidebar.** Annotate each top-level route with `meta: { sidebar: { title, group, order } }` and have `SidebarNav` derive its menu from the route table. Removes the "added a route, forgot the link" failure mode permanently. Same shape as the proposed `AdminHome` rework in `/feature-plans/admin.md` — design once, apply to both.
9. **Decide what `/dashboard` is.** Either fold `StatisticsDashboard` into `UserDashboard` (so the prominent header CTA goes somewhere useful), redirect `/dashboard` → `/library`, or build a real home dashboard with last-read / lists-summary / quick-add. Owned by `/feature-plans/auth.md` item 11; the chrome side is the header-CTA destination.
10. **Breadcrumbs.** A small `<Breadcrumbs>` component above `<RouterView>` driven by `route.matched` + `meta.breadcrumb`. Skippable until the URL depth gets uncomfortable; useful immediately for the book/list deep paths.
11. **Anonymous landing.** Either make `HomeView` a real marketing-style landing page for logged-out users (with a clear "Login" CTA distinct from the header button) or redirect anonymous `/` to `/login`. Today the home page does almost nothing.

### Layout extensibility

12. **Layout-component pattern.** Refactor so each route (or each view) declares its layout. `App.vue` becomes `<component :is="route.meta.layout ?? DefaultLayout">`. Right answer the first time a no-chrome surface is needed (print view, fullscreen reader, public marketing pages). Cheap when there's only one layout — the migration cost is low and the future cost of a one-off `v-if` in `App.vue` is high.
13. **Global toast / notification queue.** A small `useToast` composable + a `<ToastViewport>` mounted once in `App.vue`. Cuts the per-view error-rendering boilerplate; lets cross-route success messages survive a navigation.
14. **Global error boundary.** A wrapping component (or Vue's `errorCaptured`) at the layout level that renders a recover-or-reload panel on uncaught render errors.
15. **Move inline routes out of `router/index.js`.** Genres, formats, completed, statistics all warrant their own per-domain route files (some already have docs that imply ownership). `index.js` becomes pure composition.

### Mobile & responsive

16. **Drawer focus trap, `aria-modal`, return-focus on close.** Standard modal behavior. Likely 30 lines including a small focus-trap helper.
17. **Swipe-to-close on the mobile drawer.** Nice-to-have; gestures are easy to get wrong, so worth doing only if mobile usage justifies it.
18. **Audit `HomeView` / `ErrorNotFoundView` `w-1/2` panels for narrow screens.** Switch to responsive widths (`w-full lg:w-1/2`).

### Accessibility

19. **Skip-to-content link.** A visually-hidden link at the top of `App.vue` that jumps to `<main>` (which means giving `<RouterView>` a `<main id="content">` wrapper or similar).
20. **Focus-visible styles.** A global `:focus-visible` rule for buttons / links on dark backgrounds. Trivial CSS, big win for keyboard users.
21. **Color-contrast audit on `HeaderNav`.** `text-slate-400` on `text-slate-900` is borderline; bump to `text-slate-300` for inactive links.
22. **Per-route landmarks.** Each view should render a `<main>` (or the layout should). Several views start with `<div>` only; no landmark = poor screen-reader navigation.

### Page metadata

23. **Add favicon + 1× Apple touch icon.** Even a placeholder is better than the browser default.
24. **Open Graph + Twitter card meta** if the app is ever shared. Skip until then.

### Static views

25. **Flesh out `AboutView`** — version (sourced same as item 7), link to changelog, link to repo, brief credits.
26. **Make `HomeView` useful for logged-in users.** "Your last read", "Jump back into list X", "What you completed this year." Likely after the dashboard decision in item 9 — these may belong on the dashboard instead.

### Tests

27. **SPA tests for the router guard** (public/private redirect logic, login → home redirect, deep-link redirect after login). Already partly covered by `/feature-plans/auth.md` item 2; the chrome side is asserting the conditional layout matches.
28. **Test the catch-all** (item 1) renders `ErrorNotFoundView` on unknown paths.
29. **Snapshot or render-test the layout in both auth states** so a regression that hides the sidebar (or shows it to anonymous users) trips a test.

## Open questions

- **Is `HomeView` for logged-out marketing or logged-in welcome?** Today it's neither convincingly. Pick one before investing in the static views.
- **Layout-component refactor now or wait for the second layout?** Strict YAGNI says wait; the existing single-layout `App.vue` is fine for one chrome. But the *first* no-chrome surface (a focused reading view, a print-friendly bookshelf export) is cheaper to land if the pattern already exists. Likely revisit when the first concrete need lands.
- **Do we want a "Recent activity" feed on the dashboard or as a sidebar widget?** The data exists (read instances are timestamped); the UI surface doesn't. Where it lives shapes whether the dashboard becomes substantial (item 9) or the sidebar grows.
