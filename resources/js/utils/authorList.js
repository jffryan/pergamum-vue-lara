// List-shaping rules for the admin author table, kept pure so the suite can
// pin them (there is no component-test tooling yet — see
// /feature-plans/frontend-tests.md).

/**
 * "First Last", or whichever half exists — mononyms and organizations are
 * stored with the whole name in `first_name` and `last_name` empty.
 */
const authorName = (author) =>
    [author?.first_name, author?.last_name]
        .map((part) => (part ?? "").trim())
        .filter(Boolean)
        .join(" ");

/**
 * Case- and accent-insensitive substring match on the full name and the slug.
 * Matching the slug too means pasting an author page's URL tail finds them.
 * Deliberately not a RegExp: the term is raw user input.
 */
const fold = (value) =>
    (value ?? "").normalize("NFD").replace(/[̀-ͯ]/g, "").toLowerCase();

const filterAuthors = (authors, term) => {
    const needle = fold(term).trim();

    if (!needle) {
        return [...authors];
    }

    return authors.filter(
        (author) =>
            fold(authorName(author)).includes(needle) ||
            fold(author.slug).includes(needle),
    );
};

export { authorName, filterAuthors };
