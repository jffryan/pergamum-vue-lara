import { describe, it, expect } from "vitest";
import {
    SORT_OPTIONS,
    DEFAULT_SORT,
    DEFAULT_PAGE_SIZE,
    GAP,
    primaryAuthor,
    coAuthorCount,
    formatName,
    pageCount,
    latestRead,
    monthYear,
    discardedOn,
    pageBand,
    authorLetter,
    resolveSort,
    groupBooks,
    pageWindow,
    summarize,
} from "@/utils/libraryList";

// The `GET /books` row shape, with every relation optional so a test can
// build the broken states the catalog can hold (no authors, no versions).
const book = ({
    id = 1,
    title = "Untitled",
    authors = [],
    versions = [],
    reads = [],
} = {}) => ({
    book: { book_id: id, title, slug: `book-${id}` },
    authors,
    versions,
    genres: [],
    readInstances: reads,
});

const author = (first_name, last_name, slug = "a") => ({
    first_name,
    last_name,
    slug,
});

const version = (page_count, format = "Physical", extra = {}) => ({
    version_id: 1,
    page_count,
    format: { name: format, slug: format.toLowerCase() },
    ...extra,
});

const read = (date_read, rating = null) => ({ date_read, rating });

describe("utils/libraryList", () => {
    describe("row values", () => {
        it("files the row under authors[0] with a link", () => {
            const row = book({
                authors: [
                    author("Ursula", "Le Guin", "le-guin"),
                    author("X", "Y"),
                ],
            });

            expect(primaryAuthor(row)).toEqual({
                name: "Ursula Le Guin",
                slug: "le-guin",
            });
            expect(coAuthorCount(row)).toBe(1);
        });

        it("gives an authorless book a name and no link, never a string", () => {
            expect(primaryAuthor(book())).toEqual({
                name: "Unknown author",
                slug: null,
            });
            expect(coAuthorCount(book())).toBe(0);
        });

        it("tolerates a single-name author", () => {
            expect(
                primaryAuthor(book({ authors: [author("Homer", "")] })).name,
            ).toBe("Homer");
        });

        it("reads format and pages off the oldest version, or nothing", () => {
            const row = book({ versions: [version(304, "Ebook"), version(9)] });

            expect(formatName(row)).toBe("Ebook");
            expect(pageCount(row)).toBe(304);
            expect(formatName(book())).toBe("");
            expect(pageCount(book())).toBeNull();
        });

        it("takes the first (newest) read instance as the latest", () => {
            const row = book({
                reads: [read("2024-03-01", 4.5), read("2019-01-01", 3)],
            });

            expect(latestRead(row)).toEqual(read("2024-03-01", 4.5));
            expect(latestRead(book())).toBeNull();
        });

        it("formats a date as month and year", () => {
            expect(monthYear("2022-03-14")).toBe("Mar 2022");
            expect(monthYear("2022-13-01")).toBe("2022");
            expect(monthYear(null)).toBe("");
            expect(monthYear("")).toBe("");
        });

        it("reports the latest discard date across copies, ignoring kept ones", () => {
            const row = book({
                versions: [
                    version(1, "Physical", {
                        is_discarded: true,
                        discarded_at: "2020-01-01",
                    }),
                    version(1, "Physical", {
                        is_discarded: false,
                        discarded_at: "2023-01-01",
                    }),
                    version(1, "Physical", {
                        is_discarded: true,
                        discarded_at: "2021-06-01",
                    }),
                    version(1, "Physical", {
                        is_discarded: true,
                        discarded_at: null,
                    }),
                ],
            });

            expect(discardedOn(row)).toBe("2021-06-01");
            expect(discardedOn(book())).toBeNull();
        });
    });

    describe("sort options", () => {
        it("has one option per BookListing::SORTABLE key", () => {
            expect(SORT_OPTIONS.map((option) => option.key)).toEqual([
                "author",
                "title",
                "date_read",
                "rating",
                "pages",
                "format",
            ]);
            expect(DEFAULT_SORT).toBe("author");
        });

        it("falls back to the default sort on an unknown key", () => {
            expect(resolveSort({ sort: "colour" })).toEqual({
                key: "author",
                direction: "asc",
            });
            expect(resolveSort()).toEqual({ key: "author", direction: "asc" });
        });

        it("uses the option's natural direction when the URL has none", () => {
            expect(resolveSort({ sort: "rating" })).toEqual({
                key: "rating",
                direction: "desc",
            });
            expect(resolveSort({ sort: "title" })).toEqual({
                key: "title",
                direction: "asc",
            });
        });

        it("honours an explicit direction and ignores a malformed one", () => {
            expect(
                resolveSort({ sort: "rating", direction: "asc" }).direction,
            ).toBe("asc");
            expect(
                resolveSort({ sort: "rating", direction: "sideways" })
                    .direction,
            ).toBe("desc");
        });
    });

    describe("authorLetter", () => {
        it("files by last name, falling back to first, then to a label", () => {
            expect(
                authorLetter(book({ authors: [author("Douglas", "Adams")] })),
            ).toBe("A");
            expect(
                authorLetter(book({ authors: [author("Homer", " ")] })),
            ).toBe("H");
            expect(
                authorLetter(
                    book({ authors: [author("", "1984 Collective")] }),
                ),
            ).toBe("#");
            expect(authorLetter(book())).toBe("Unknown author");
        });
    });

    describe("pageBand", () => {
        it("bands on exclusive upper bounds", () => {
            expect(pageBand(0)).toBe("Under 200 pages");
            expect(pageBand(199)).toBe("Under 200 pages");
            expect(pageBand(200)).toBe("200–399 pages");
            expect(pageBand(599)).toBe("400–599 pages");
            expect(pageBand(800)).toBe("800+ pages");
            expect(pageBand(2000)).toBe("800+ pages");
            expect(pageBand(null)).toBe("Unknown length");
        });
    });

    describe("groupBooks", () => {
        it("folds adjacent rows with the same heading, preserving order", () => {
            const rows = [
                book({ id: 1, authors: [author("D", "Adams")] }),
                book({ id: 2, authors: [author("M", "Atwood")] }),
                book({ id: 3, authors: [author("J", "Baldwin")] }),
            ];

            expect(groupBooks(rows, "author")).toEqual([
                { label: "A", books: [rows[0], rows[1]] },
                { label: "B", books: [rows[2]] },
            ]);
        });

        it("never merges a heading that recurs later on the page", () => {
            const rows = [
                book({ id: 1, title: "Alpha" }),
                book({ id: 2, title: "Beta" }),
                book({ id: 3, title: "Ambient" }),
            ];

            expect(
                groupBooks(rows, "title").map((section) => section.label),
            ).toEqual(["A", "B", "A"]);
        });

        it("groups date_read by year with one shared heading for the undated", () => {
            const rows = [
                book({ id: 1, reads: [read("2024-05-01")] }),
                book({ id: 2, reads: [read("2024-01-01")] }),
                book({ id: 3, reads: [read(null, 4)] }),
                book({ id: 4 }),
            ];

            expect(groupBooks(rows, "date_read")).toEqual([
                { label: "2024", books: [rows[0], rows[1]] },
                { label: "No date read", books: [rows[2], rows[3]] },
            ]);
        });

        it("groups rating by star value with the unread under Unrated", () => {
            const rows = [
                book({ id: 1, reads: [read("2024-01-01", 5)] }),
                book({ id: 2, reads: [read("2024-01-01", 4.5)] }),
                book({ id: 3 }),
            ];

            expect(
                groupBooks(rows, "rating").map((section) => section.label),
            ).toEqual(["★ 5", "★ 4.5", "Unrated"]);
        });

        it("groups format by name and versionless books under No copies", () => {
            const rows = [
                book({ id: 1, versions: [version(1, "Audiobook")] }),
                book({ id: 2 }),
            ];

            expect(
                groupBooks(rows, "format").map((section) => section.label),
            ).toEqual(["Audiobook", "No copies"]);
        });

        it("uses the default grouping for an unknown key and handles an empty page", () => {
            const rows = [book({ id: 1, authors: [author("D", "Adams")] })];

            expect(groupBooks(rows, "colour")[0].label).toBe("A");
            expect(groupBooks([], "author")).toEqual([]);
        });
    });

    describe("pageWindow", () => {
        it("is empty for a single page", () => {
            expect(pageWindow(1, 1)).toEqual([]);
            expect(pageWindow(1, 0)).toEqual([]);
        });

        it("lists every page when there is nothing to elide", () => {
            expect(pageWindow(3, 5)).toEqual([1, 2, 3, 4, 5]);
        });

        it("keeps first, last and the radius around the current page", () => {
            expect(pageWindow(7, 14)).toEqual([1, GAP, 5, 6, 7, 8, 9, GAP, 14]);
            expect(pageWindow(1, 14)).toEqual([1, 2, 3, GAP, 14]);
            expect(pageWindow(14, 14)).toEqual([1, GAP, 12, 13, 14]);
        });

        it("shows a page rather than eliding a run of one", () => {
            expect(pageWindow(5, 9)).toEqual([1, 2, 3, 4, 5, 6, 7, 8, 9]);
            expect(pageWindow(5, 10)).toEqual([1, 2, 3, 4, 5, 6, 7, GAP, 10]);
            expect(pageWindow(6, 11)).toEqual([1, GAP, 4, 5, 6, 7, 8, GAP, 11]);
        });

        it("exports a page size the sections can breathe in", () => {
            expect(DEFAULT_PAGE_SIZE).toBeGreaterThan(20);
        });
    });

    describe("summarize", () => {
        it("counts plainly with no filters", () => {
            expect(summarize({ total: 676 })).toBe("676 books");
            expect(summarize({ total: 1 })).toBe("1 book");
        });

        it("names the read status and the shelf", () => {
            expect(summarize({ total: 640, read: "unread" })).toBe(
                "640 unread books",
            );
            expect(summarize({ total: 3, discarded: true })).toBe(
                "3 books discarded",
            );
            expect(summarize({ total: 2, read: "read", discarded: true })).toBe(
                "2 read books discarded",
            );
            expect(summarize({ total: 5, read: "whatever" })).toBe("5 books");
        });

        it("quotes the search term", () => {
            expect(summarize({ total: 12, search: " le guin " })).toBe(
                "12 books match “le guin”",
            );
            expect(summarize({ total: 1, search: "x", read: "unread" })).toBe(
                "1 unread book matches “x”",
            );
            expect(summarize({ total: 0, search: "zzz" })).toBe(
                "0 books match “zzz”",
            );
        });
    });
});
