// List-shaping rules for the genre index. These live here rather than as
// computeds on `GenresView` because they are the part worth pinning and the
// suite has no component-test tooling yet (see /feature-plans/frontend-tests.md).
//
// Everything below is pure and never mutates its input — `Array.sort` is
// in-place, and `allGenres` is the store's cached payload, shared with
// `GenreTagInput`.

// Genres that don't start with a letter file under one bucket at the end,
// mirroring the server's `CASE WHEN name REGEXP "^[0-9]"` ordering in
// `GenreController::index` — "20th century" belongs after "zoology", not before "a".
const OTHER_LETTER = "#";

const ALPHABET = "ABCDEFGHIJKLMNOPQRSTUVWXYZ".split("");

const LETTERS = [...ALPHABET, OTHER_LETTER];

// Bars are floored so a one-book genre still reads as a bar rather than as
// nothing at all.
const MIN_BAR_PERCENT = 3;

const bookCount = (genre) => genre.books_count ?? 0;

/**
 * Case-insensitive substring match. Deliberately not a RegExp: the term is raw
 * user input, and `new RegExp("(")` throws — which is what used to take the
 * whole view down mid-keystroke.
 */
const filterGenres = (genres, term) => {
    const needle = (term ?? "").trim().toLowerCase();

    if (!needle) {
        return [...genres];
    }

    return genres.filter((genre) => genre.name.toLowerCase().includes(needle));
};

const SORTS = {
    name: (a, b) => a.name.localeCompare(b.name),
    // Ties broken by name so the order is stable and the alphabet stays
    // readable inside a run of equal counts.
    count: (a, b) =>
        bookCount(b) - bookCount(a) || a.name.localeCompare(b.name),
};

const SORT_KEYS = Object.keys(SORTS);

const DEFAULT_SORT = "name";

/**
 * An unrecognized key falls back to the default rather than throwing, matching
 * how `BookController::index` treats `?sort=`.
 */
const sortGenres = (genres, sortKey) =>
    [...genres].sort(SORTS[sortKey] ?? SORTS[DEFAULT_SORT]);

const letterFor = (name) => {
    const initial = (name ?? "").trim().charAt(0).toUpperCase();

    return ALPHABET.includes(initial) ? initial : OTHER_LETTER;
};

/**
 * Groups a *name-sorted* list into `{ letter, genres }` sections. Order within
 * a section is the order it arrived in, so the caller owns the sort.
 */
const groupByLetter = (genres) => {
    const sections = new Map();

    genres.forEach((genre) => {
        const letter = letterFor(genre.name);

        if (!sections.has(letter)) {
            sections.set(letter, { letter, genres: [] });
        }

        sections.get(letter).genres.push(genre);
    });

    return LETTERS.filter((letter) => sections.has(letter)).map((letter) =>
        sections.get(letter),
    );
};

/**
 * Returns a `count -> percentage` function on a square-root scale.
 *
 * The distribution is severely long-tailed — the largest genre currently holds
 * 383 books and 39 hold exactly one. Linear widths would render the tail as a
 * uniform sliver; sizing the *text* instead (a tag cloud) would make most of
 * the page unreadable and encode data as font size. Square root compresses the
 * head enough to leave the tail distinguishable.
 *
 * Build this against the full list, not the filtered one, so bars don't
 * rescale under the user while they type.
 */
const countScale = (genres) => {
    const max = genres.reduce(
        (high, genre) => Math.max(high, bookCount(genre)),
        0,
    );

    if (max <= 0) {
        return () => 0;
    }

    return (count) => {
        if (count <= 0) {
            return 0;
        }

        const percent = (Math.sqrt(count) / Math.sqrt(max)) * 100;

        return Math.max(MIN_BAR_PERCENT, Math.round(percent));
    };
};

export {
    LETTERS,
    OTHER_LETTER,
    SORT_KEYS,
    DEFAULT_SORT,
    filterGenres,
    sortGenres,
    letterFor,
    groupByLetter,
    countScale,
};
