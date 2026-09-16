/**
 * App version, derived from CHANGELOG.md.
 *
 * The changelog is the one place a release is recorded, so the version the
 * SPA displays is read from it at build time rather than kept in a second
 * spot that has to be remembered. `vite.config.js` calls this on the file's
 * contents and injects the result as the `__APP_VERSION__` global; components
 * read that global, not the changelog.
 *
 * Kept as a pure function (and out of `vite.config.js`) so it can be unit
 * tested like the other utils.
 */

const HEADING = /^## \[(\d+\.\d+\.\d+)\]/m;

/**
 * The version of the topmost `## [x.y.z]` heading, or null when the text has
 * none. Entries are newest-first, so the first heading is the current release.
 */
export default function parseChangelogVersion(markdown) {
    const match = HEADING.exec(markdown ?? "");
    return match ? match[1] : null;
}
