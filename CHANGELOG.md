# Changelog

All notable changes to Pergamum will be documented in this file.

## [0.1.1] - 2026-04-28

- Upgraded Laravel from 9 to 10. PHP minimum is now 8.1.

## [0.1.0] - 2026-04-26

Baseline snapshot of the app prior to formal changelog tracking. Earlier history is captured in git only.

- Laravel 9 / PHP 8 JSON API backed by MySQL 8, with Sanctum token auth.
- Vue 3 + Pinia + Vue Router SPA served from a single Blade entrypoint, bundled with Vite and styled with Tailwind.
- Core domain: books, authors, genres, formats, versions, read instances, and lists (with custom `_id` primary keys throughout).
- Book/author/format detail pages with slug-based URLs, plus mobile-responsive layouts.
- Reading history tracking (optional per book) with ratings and read dates, and related-books-by-author surfacing.
- User-curated lists with list items keyed to versions, plus list statistics.
- Genre handling and statistics views powered by `StatisticsService`.
- Edit flow hardened against common bug patterns.
- Dockerized dev environment (`php`, `nginx`, `vite`, `db`) on the `appnet` network.
