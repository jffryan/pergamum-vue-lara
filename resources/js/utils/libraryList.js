// List-shaping rules for the library page. Pure functions over the
// `GET /books` payload, kept out of the view so they can be pinned by the
// suite (see /feature-plans/frontend-tests.md for why the view itself can't).
//
// The listing is sorted and paginated server-side, so nothing here reorders.
// What this file decides is how a page of already-ordered rows is *read*:
// which value each row surfaces, and how consecutive rows fold into headed
// sections so that a sort by author reads as a shelf and a sort by date read
// reads as a timeline rather than either reading as a spreadsheet.

import { letterFor } from "@/utils/genreList";

// Shared with `utils/bookDetail.js`, which renders the same months in a
// day-precise form — one month table in the app.
const MONTHS = [
    "Jan",
    "Feb",
    "Mar",
    "Apr",
    "May",
    "Jun",
    "Jul",
    "Aug",
    "Sep",
    "Oct",
    "Nov",
    "Dec",
];

// --- Row values -------------------------------------------------------------

const fullName = (author) =>
    `${author?.first_name || ""} ${author?.last_name || ""}`.trim();

/**
 * The author the row files under — `authors[0]`, which the server orders by
 * `author_ordinal` so it is the primary author, not an arbitrary one. Always
 * an object: a book with no authors gets a name and no slug, so the template's
 * "link when there is somewhere to go" check stays a single truthiness test.
 */
const primaryAuthor = (book) => {
    const author = book.authors?.[0];

    if (!author) {
        return { name: "Unknown author", slug: null };
    }

    return { name: fullName(author) || "Unknown author", slug: author.slug };
};

const coAuthorCount = (book) => Math.max(0, (book.authors?.length ?? 0) - 1);

/**
 * The copy the row describes — `versions[0]`, the oldest, matching the value
 * the server sorts `format` and `pages` on. `null` when the book has no
 * copies at all, a state the catalog can hold.
 */
const primaryVersion = (book) => book.versions?.[0] ?? null;

const formatName = (book) => primaryVersion(book)?.format?.name ?? "";

const pageCount = (book) => primaryVersion(book)?.page_count ?? null;

/**
 * The most recent read, or `null`. `readInstances` arrives newest-first and
 * scoped to the current user, so `[0]` is "the last time you read this".
 */
const latestRead = (book) => book.readInstances?.[0] ?? null;

/**
 * "Mar 2022" from an ISO date. Month-and-year is deliberate: the library is
 * for recognising *when* you read something, and the day adds width the row
 * doesn't have. The book page still shows the full date.
 */
const monthYear = (isoDate) => {
    if (!isoDate) {
        return "";
    }

    const [year, month] = isoDate.split("-");
    const label = MONTHS[Number(month) - 1];

    return label ? `${label} ${year}` : year;
};

/**
 * Every discarded copy's date, latest first. In the discarded shelf the row
 * shows this where an on-shelf row shows the read marker.
 */
const discardedOn = (book) => {
    const dates = (book.versions ?? [])
        .filter((version) => version.is_discarded)
        .map((version) => version.discarded_at)
        .filter(Boolean)
        .sort()
        .reverse();

    return dates[0] ?? null;
};

// --- Sorting ----------------------------------------------------------------

// Length bands for the "pages" grouping. Upper bounds are exclusive; the last
// entry catches everything above the penultimate bound.
const PAGE_BANDS = [
    { under: 200, label: "Under 200 pages" },
    { under: 400, label: "200–399 pages" },
    { under: 600, label: "400–599 pages" },
    { under: 800, label: "600–799 pages" },
    { under: Infinity, label: "800+ pages" },
];

const pageBand = (count) => {
    if (count === null || count === undefined) {
        return "Unknown length";
    }

    return PAGE_BANDS.find((band) => count < band.under).label;
};

// Author letters come from the filing name — last name, or first when there
// is no last — because that's what the server's `sort_author` ranks on. Using
// the display name would put "Douglas Adams" under D in a list ordered by A.
const authorLetter = (book) => {
    const author = book.authors?.[0];
    const filing = (author?.last_name || "").trim() || author?.first_name;

    return filing ? letterFor(filing) : "Unknown author";
};

/**
 * The sort menu, in display order. `direction` is what choosing the option
 * fresh gives you: names read A→Z, everything numeric reads biggest first,
 * because "sort by rating" means "show me the best" nine times out of ten.
 *
 * `group` maps a row to its section heading. Sections are runs of adjacent
 * rows with the same heading (see `groupBooks`), so a grouping only makes
 * sense if the server's ordering for that key keeps equal headings together —
 * which is exactly what "sorted by this key" guarantees.
 *
 * Keys match `BookListing::SORTABLE`.
 */
const SORT_OPTIONS = [
    {
        key: "author",
        label: "Author",
        direction: "asc",
        group: authorLetter,
    },
    {
        key: "title",
        label: "Title",
        direction: "asc",
        group: (book) => letterFor(book.book?.title),
    },
    {
        key: "date_read",
        label: "Date read",
        direction: "desc",
        // Read-but-undated and never-read both sort null and interleave, so
        // they share a heading rather than alternating one-row sections.
        group: (book) => {
            const date = latestRead(book)?.date_read;

            return date ? date.slice(0, 4) : "No date read";
        },
    },
    {
        key: "rating",
        label: "Rating",
        direction: "desc",
        group: (book) => {
            const rating = latestRead(book)?.rating;

            return rating === null || rating === undefined
                ? "Unrated"
                : `★ ${rating}`;
        },
    },
    {
        key: "pages",
        label: "Length",
        direction: "desc",
        group: (book) => pageBand(pageCount(book)),
    },
    {
        key: "format",
        label: "Format",
        direction: "asc",
        group: (book) => formatName(book) || "No copies",
    },
];

const DEFAULT_SORT = "author";

const sortOption = (key) =>
    SORT_OPTIONS.find((option) => option.key === key) ??
    SORT_OPTIONS.find((option) => option.key === DEFAULT_SORT);

/**
 * `{ key, direction }` from the URL. An unrecognized key falls back to the
 * default, as the server does, so a stale bookmark renders the library and
 * the menu points at the sort that is actually applied. An absent direction
 * is the option's natural one, not "asc", so `?sort=rating` alone shows the
 * best-rated first — that is what `?sort=rating` means to a person.
 */
const resolveSort = (query = {}) => {
    const option = sortOption(query.sort);
    const direction =
        query.direction === "asc" || query.direction === "desc"
            ? query.direction
            : option.direction;

    return { key: option.key, direction };
};

/**
 * Folds an ordered page of books into `[{ label, books }]` sections, one per
 * run of adjacent equal headings. Never reorders: the server owns the sort,
 * and a heading that recurs later on the page (it shouldn't, if the sort
 * matches the grouping) starts a second section rather than being merged
 * backwards into the first.
 */
const groupBooks = (books, sortKey) => {
    const { group } = sortOption(sortKey);
    const sections = [];

    books.forEach((book) => {
        const label = group(book);
        const current = sections[sections.length - 1];

        if (current && current.label === label) {
            current.books.push(book);
        } else {
            sections.push({ label, books: [book] });
        }
    });

    return sections;
};

// --- Pagination -------------------------------------------------------------

const PAGE_SIZES = [20, 50, 100];

// Bigger than the server's own default (20). Sections only read as sections
// when a page holds enough rows for a heading to cover more than one.
const DEFAULT_PAGE_SIZE = 50;

const GAP = "…";

/**
 * The page numbers worth a link: the first, the last, and `radius` either
 * side of the current one, with `"…"` standing in for each elided run. A
 * run of one is shown rather than elided — "4 … 6" saves nothing over "4 5 6".
 */
const pageWindow = (current, last, radius = 2) => {
    if (last <= 1) {
        return [];
    }

    const wanted = new Set([1, last]);

    for (let page = current - radius; page <= current + radius; page += 1) {
        if (page >= 1 && page <= last) {
            wanted.add(page);
        }
    }

    const pages = [...wanted].sort((a, b) => a - b);
    const items = [];

    pages.forEach((page, index) => {
        const previous = pages[index - 1];

        if (previous !== undefined && page - previous === 2) {
            items.push(previous + 1);
        } else if (previous !== undefined && page - previous > 2) {
            items.push(GAP);
        }

        items.push(page);
    });

    return items;
};

// --- Summary ----------------------------------------------------------------

const plural = (count, noun) => `${count} ${noun}${count === 1 ? "" : "s"}`;

/**
 * One sentence describing what the list is showing, built from the same
 * state the request was. "676 books", "640 unread books", "3 books discarded",
 * "12 books match “le guin”".
 */
const summarize = ({ total, search, read, discarded }) => {
    const qualifier = read === "read" || read === "unread" ? `${read} ` : "";
    const noun = `${qualifier}book`;
    const trimmed = (search ?? "").trim();
    const shelf = discarded ? " discarded" : "";

    if (trimmed) {
        const verb = total === 1 ? "matches" : "match";

        return `${plural(total, noun)}${shelf} ${verb} “${trimmed}”`;
    }

    return `${plural(total, noun)}${shelf}`;
};

export {
    MONTHS,
    SORT_OPTIONS,
    DEFAULT_SORT,
    PAGE_SIZES,
    DEFAULT_PAGE_SIZE,
    GAP,
    primaryAuthor,
    coAuthorCount,
    primaryVersion,
    formatName,
    pageCount,
    latestRead,
    monthYear,
    discardedOn,
    pageBand,
    authorLetter,
    sortOption,
    resolveSort,
    groupBooks,
    pageWindow,
    summarize,
};
