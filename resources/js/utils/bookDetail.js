// Detail-shaping rules for the book page. Pure functions over the
// `GET /book/{slug}` payload, kept out of the view so they can be pinned by
// the suite — the same split `utils/libraryList.js` makes for the library.
//
// The library page answers "what is in the library". This one answers three
// questions about one book: have I read it, what copies do I own, and where
// are they. The payload already holds every answer — `readInstances` hangs
// off `versions` by `version_id`, `versions` carry a `format` that declares
// which length field the medium has, and a `location` when the copy is
// shelved — but nothing joined them up, so the page used to render a copies
// table and a read-history panel that never referred to each other.
//
// Ordering is the server's, as it is for the library: `BookService::
// getBookWithRelations` orders authors by `author_ordinal`, copies
// oldest-first and reads newest-first (undated reads last, since MySQL sorts
// NULL lowest). Nothing here reorders except `orderedCopies`, which says why.

import { MONTHS, monthYear } from "@/utils/libraryList";

// --- Dates ------------------------------------------------------------------

/**
 * "12 Mar 2022" from an ISO date. The library deliberately shows month and
 * year (`monthYear`) because a row has no width for the day; a book's own
 * page is where the exact date belongs. Returns "" for a missing date —
 * `read_instances.date_read` is nullable and means "read, date unknown".
 */
const fullDate = (isoDate) => {
    if (!isoDate) {
        return "";
    }

    const [year, month, day] = isoDate.split("-");
    const label = MONTHS[Number(month) - 1];

    if (!label) {
        return year ?? "";
    }

    return day ? `${Number(day)} ${label} ${year}` : `${label} ${year}`;
};

const plural = (count, noun, plural_ = `${noun}s`) =>
    `${count} ${count === 1 ? noun : plural_}`;

const DAY = 86400000;

/**
 * How long after the previous read this one came — "8 years later", "3 months
 * later". The unit steps up as the gap grows because "2,947 days later" is
 * not a fact anyone holds; rounding to the coarsest honest unit is.
 *
 * "" when either date is missing or the pair is out of order, which is also
 * what the first (oldest) read gets: it came after nothing.
 */
const gapLabel = (laterIso, earlierIso) => {
    if (!laterIso || !earlierIso) {
        return "";
    }

    const later = Date.parse(`${laterIso}T00:00:00Z`);
    const earlier = Date.parse(`${earlierIso}T00:00:00Z`);

    if (!Number.isFinite(later) || !Number.isFinite(earlier)) {
        return "";
    }

    const days = Math.round((later - earlier) / DAY);

    if (days <= 0) {
        return "";
    }

    if (days < 30) {
        return `${plural(days, "day")} later`;
    }

    const months = Math.round(days / 30.44);

    if (months < 18) {
        return `${plural(months, "month")} later`;
    }

    return `${plural(Math.round(days / 365.25), "year")} later`;
};

// --- Copies -----------------------------------------------------------------

/**
 * "21h 8m", "45m", "2h". Differs from `BookServices.calculateRuntime`, which
 * pads to "0h 45m" for a fixed-width table column; this one reads inline in a
 * sentence, where the zero hours are noise. "" for anything not a positive
 * number, so a copy with no recorded runtime renders nothing rather than "0m".
 */
const runtimeLabel = (minutes) => {
    const total = Number(minutes);

    if (!Number.isFinite(total) || total <= 0) {
        return "";
    }

    const hours = Math.floor(total / 60);
    const rest = Math.round(total % 60);

    if (!hours) {
        return `${rest}m`;
    }

    return rest ? `${hours}h ${rest}m` : `${hours}h`;
};

/**
 * How long this copy is, in the unit its medium actually has — "412 pp" for a
 * paperback, "21h 8m" for an audiobook.
 *
 * The format row is the config for that (`Format::expectedLengthFields()`, the
 * same `expects_page_count` / `expects_audio_runtime` pair every write path
 * reduces through), so a new medium is a row in `formats` rather than a branch
 * here. A copy whose format declares pages but has none recorded renders
 * nothing — the honest rendering of "length unknown" — instead of falling
 * through to a runtime the medium cannot have. When the format itself is
 * missing, fall back to whatever values are present.
 */
const lengthLabel = (version) => {
    if (!version) {
        return "";
    }

    const { format } = version;
    const parts = [];

    if (!format || format.expects_page_count) {
        const pages = Number(version.page_count);

        if (Number.isFinite(pages) && pages > 0) {
            parts.push(`${pages} pp`);
        }
    }

    if (!format || format.expects_audio_runtime) {
        const runtime = runtimeLabel(version.audio_runtime);

        if (runtime) {
            parts.push(runtime);
        }
    }

    return parts.join(" · ");
};

const formatLabel = (version) => version?.format?.name || "Unknown format";

/**
 * The shortest name that tells this copy apart from the others. The nickname
 * is what a person actually calls a copy ("Dad's hardback"); the format is the
 * fallback, and is enough whenever the copies differ by medium.
 */
const copyLabel = (version) =>
    version?.nickname?.trim() || formatLabel(version);

/**
 * Where the copy is, or "" when it is unshelved. `name` is the human label and
 * `code` the machine identity — see the `Location` model; the UI prefers the
 * name whenever one is set.
 */
const locationLabel = (version) => {
    const location = version?.location;

    if (!location) {
        return "";
    }

    return location.name || location.code || "";
};

/**
 * One of "discarded", "shelved", "unshelved".
 *
 * Read from `is_discarded`, never from `discarded_at`: much of the historical
 * library was got rid of at an unrecoverable date, so a null date means
 * "discarded, date unknown" rather than "not discarded".
 */
const copyState = (version) => {
    if (version?.is_discarded) {
        return "discarded";
    }

    return version?.location ? "shelved" : "unshelved";
};

/**
 * Copies you still own first, discarded ones after, each group keeping the
 * server's oldest-first order. This is the one place that reorders, because
 * the server has no opinion worth preserving here and the page's question is
 * "what do I have" — a copy that went to the charity shop in 2019 is
 * provenance, not inventory.
 */
const orderedCopies = (versions = []) => [
    ...versions.filter((version) => !version.is_discarded),
    ...versions.filter((version) => version.is_discarded),
];

const copyCounts = (versions = []) => {
    const discarded = versions.filter((version) => version.is_discarded).length;

    return {
        total: versions.length,
        onShelf: versions.length - discarded,
        discarded,
    };
};

/**
 * "2 copies · 1 discarded", "1 copy", "No copies". The second clause only
 * appears when something has been discarded, so the common case stays short.
 */
const copySummary = (versions = []) => {
    const { total, onShelf, discarded } = copyCounts(versions);

    if (!total) {
        return "No copies";
    }

    const copies = (count) => plural(count, "copy", "copies");

    if (!discarded) {
        return copies(total);
    }

    if (!onShelf) {
        return `${copies(total)}, all discarded`;
    }

    return `${copies(onShelf)} · ${discarded} discarded`;
};

// --- Reads ------------------------------------------------------------------

/**
 * The read history, newest first, with each read joined to the copy it
 * happened on. That join is `read_instances.version_id`, which the payload has
 * always carried and the old page threw away — it rendered the *format name*
 * under the heading "Version", so two paperbacks were indistinguishable and a
 * read whose copy had since been discarded looked like a read of the copy on
 * the shelf.
 *
 * `copy` is the version row or `null` (a read can outlive nothing in practice,
 * but the payload shape allows it); `gap` is how long after the previous read
 * this one came. Keys stay `read_instance_id` rather than being projected onto
 * a generic `id` — nothing in this codebase has an `id`.
 */
const readsFor = (book) => {
    const reads = book?.readInstances ?? [];
    const versions = book?.versions ?? [];

    return reads.map((read, index) => {
        const copy =
            versions.find(
                (version) => version.version_id === read.version_id,
            ) ?? null;
        const previous = reads[index + 1];

        return {
            read_instance_id: read.read_instance_id,
            date_read: read.date_read ?? null,
            dateLabel: fullDate(read.date_read),
            // The API sends the display scale and uses 0 for "never rated".
            rating: read.rating ? read.rating : null,
            copy,
            copyLabel: copy ? copyLabel(copy) : "",
            copyIsDiscarded: Boolean(copy?.is_discarded),
            gap: gapLabel(read.date_read, previous?.date_read),
        };
    });
};

/**
 * How many times this book has been read on each copy, keyed by `version_id`.
 * Lets a copy row say "read twice" without the copies section having to walk
 * the read history itself.
 */
const readsByCopy = (book) => {
    const counts = new Map();

    (book?.readInstances ?? []).forEach((read) => {
        if (read.version_id === null || read.version_id === undefined) {
            return;
        }

        counts.set(read.version_id, (counts.get(read.version_id) ?? 0) + 1);
    });

    return counts;
};

const round = (value) => Math.round(value * 10) / 10;

/**
 * The reading relationship as numbers: how many times, the average of the
 * ratings that exist, and the first and last dated reads.
 *
 * Unrated reads are excluded from the average rather than counted as zero —
 * most of the historical library was read long before ratings were recorded,
 * and averaging those in would drag every old favourite toward nothing.
 */
const readingSummary = (book) => {
    const reads = book?.readInstances ?? [];
    const ratings = reads
        .map((read) => Number(read.rating))
        .filter((rating) => Number.isFinite(rating) && rating > 0);
    const dates = reads
        .map((read) => read.date_read)
        .filter(Boolean)
        .sort();

    return {
        count: reads.length,
        isUnread: reads.length === 0,
        rated: ratings.length,
        average: ratings.length
            ? round(ratings.reduce((sum, r) => sum + r, 0) / ratings.length)
            : null,
        first: dates[0] ?? null,
        last: dates[dates.length - 1] ?? null,
    };
};

const TIMES = ["", "once", "twice", "three times", "four times", "five times"];

const timesRead = (count) => TIMES[count] ?? `${count} times`;

/**
 * The one line under the title that says what this book is to you. Built from
 * the same values `readingSummary` returns, in decreasing order of what a
 * person wants to know: whether you read it, what you thought, and when.
 *
 *   "Unread"
 *   "Read once · ★ 4.5 · Mar 2022"
 *   "Read three times · ★ 4.3 average · Jun 2008 – Mar 2022"
 *   "Read twice, dates unknown"
 *
 * A single rating is not an "average", and a re-read at the same month twice
 * is one date rather than a range — the sentence says only what is true.
 */
const readingLine = (book) => {
    const { count, isUnread, average, rated, first, last } =
        readingSummary(book);

    if (isUnread) {
        return "Unread";
    }

    const parts = [`Read ${timesRead(count)}`];

    if (average !== null) {
        parts.push(rated > 1 ? `★ ${average} average` : `★ ${average}`);
    }

    const from = monthYear(first);
    const to = monthYear(last);

    if (from && to && from !== to) {
        parts.push(`${from} – ${to}`);
    } else if (to) {
        parts.push(to);
    } else {
        return `${parts.join(" · ")}, ${count === 1 ? "date" : "dates"} unknown`;
    }

    return parts.join(" · ");
};

// --- Authors ----------------------------------------------------------------

/**
 * Every author, in `author_ordinal` order, shaped so the template's "link when
 * there is somewhere to go" check is a single truthiness test — the same
 * shaped-fallback rule `libraryList.primaryAuthor` follows, and the one
 * `BookTableRow` still gets wrong by returning the bare string "Unknown".
 */
const authorList = (book) =>
    (book?.authors ?? []).map((author) => ({
        author_id: author.author_id,
        name:
            `${author.first_name || ""} ${author.last_name || ""}`.trim() ||
            "Unknown author",
        slug: author.slug || null,
    }));

// --- Lists ------------------------------------------------------------------

/**
 * The lists holding any copy of this book, with the copies they hold. Lists
 * reference `version_id`, not `book_id` (see `/documentation/lists.md`), so a
 * list can point at the audiobook while you are looking at the paperback —
 * naming the copy is the only way that reads correctly.
 */
const listsHolding = (book, lists = []) => {
    const versions = new Map(
        (book?.versions ?? []).map((version) => [version.version_id, version]),
    );

    return lists
        .map((list) => {
            const held = (list.items ?? [])
                .map((item) => versions.get(item.version_id))
                .filter(Boolean);

            return { list, copies: held.map(copyLabel) };
        })
        .filter((entry) => entry.copies.length > 0);
};

export {
    fullDate,
    gapLabel,
    runtimeLabel,
    lengthLabel,
    formatLabel,
    copyLabel,
    locationLabel,
    copyState,
    orderedCopies,
    copyCounts,
    copySummary,
    readsFor,
    readsByCopy,
    readingSummary,
    timesRead,
    readingLine,
    authorList,
    listsHolding,
};
