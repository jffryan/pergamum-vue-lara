import { describe, it, expect } from "vitest";
import {
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
} from "@/utils/bookDetail";

// The `GET /book/{slug}` payload, with every relation optional so a test can
// build the states the catalog can actually hold: no authors, no copies, a
// read with no date, a copy with no format.
const book = ({
    id = 1,
    title = "Untitled",
    authors = [],
    versions = [],
    genres = [],
    reads = [],
    related = [],
} = {}) => ({
    book: { book_id: id, title, slug: `book-${id}` },
    authors,
    versions,
    genres,
    readInstances: reads,
    authorRelatedBooks: related,
});

const PHYSICAL = {
    format_id: 1,
    name: "Physical",
    slug: "physical",
    expects_page_count: true,
    expects_audio_runtime: false,
};

const AUDIO = {
    format_id: 2,
    name: "Audiobook",
    slug: "audiobook",
    expects_page_count: false,
    expects_audio_runtime: true,
};

const copy = ({
    version_id = 1,
    format = PHYSICAL,
    page_count = null,
    audio_runtime = null,
    nickname = null,
    is_discarded = false,
    discarded_at = null,
    location = null,
} = {}) => ({
    version_id,
    format,
    page_count,
    audio_runtime,
    nickname,
    is_discarded,
    discarded_at,
    location_id: location?.location_id ?? null,
    location,
});

const read = ({
    read_instance_id = 1,
    date_read = null,
    rating = null,
    version_id = 1,
} = {}) => ({ read_instance_id, date_read, rating, version_id });

const shelf = (name, code = "O1S5") => ({
    location_id: 9,
    name,
    code,
    slug: code.toLowerCase(),
});

describe("utils/bookDetail", () => {
    describe("dates", () => {
        it("renders a full date, with the day unpadded", () => {
            expect(fullDate("2022-03-12")).toBe("12 Mar 2022");
            expect(fullDate("2022-03-01")).toBe("1 Mar 2022");
        });

        it("falls back to the year on an unparseable month", () => {
            expect(fullDate("2022-13-01")).toBe("2022");
        });

        it("renders nothing for a missing date", () => {
            expect(fullDate(null)).toBe("");
            expect(fullDate("")).toBe("");
        });

        it("steps the gap unit up as the gap grows", () => {
            expect(gapLabel("2022-03-12", "2022-03-01")).toBe("11 days later");
            expect(gapLabel("2022-03-02", "2022-03-01")).toBe("1 day later");
            expect(gapLabel("2022-06-01", "2022-03-01")).toBe("3 months later");
            expect(gapLabel("2022-03-01", "2014-06-03")).toBe("8 years later");
        });

        it("has no gap without two dates, or when they run backwards", () => {
            expect(gapLabel("2022-03-12", null)).toBe("");
            expect(gapLabel(null, "2022-03-12")).toBe("");
            expect(gapLabel("2014-06-03", "2022-03-01")).toBe("");
            expect(gapLabel("2022-03-01", "2022-03-01")).toBe("");
        });
    });

    describe("copy length", () => {
        it("drops the zero hours a table column would pad", () => {
            expect(runtimeLabel(1268)).toBe("21h 8m");
            expect(runtimeLabel(45)).toBe("45m");
            expect(runtimeLabel(120)).toBe("2h");
        });

        it("renders nothing rather than a zero runtime", () => {
            expect(runtimeLabel(0)).toBe("");
            expect(runtimeLabel(null)).toBe("");
            expect(runtimeLabel(undefined)).toBe("");
            expect(runtimeLabel("nope")).toBe("");
        });

        it("asks the format which length the medium carries", () => {
            expect(lengthLabel(copy({ page_count: 412 }))).toBe("412 pp");
            expect(
                lengthLabel(copy({ format: AUDIO, audio_runtime: 1268 })),
            ).toBe("21h 8m");
        });

        it("ignores a length the format does not declare", () => {
            // An audiobook re-filed from a paperback can carry a stale page
            // count; the format is what decides, not the column.
            const stale = copy({
                format: AUDIO,
                page_count: 412,
                audio_runtime: 1268,
            });

            expect(lengthLabel(stale)).toBe("21h 8m");
        });

        it("says nothing when the declared length is missing", () => {
            expect(lengthLabel(copy({ page_count: null }))).toBe("");
            expect(lengthLabel(copy({ format: AUDIO }))).toBe("");
        });

        it("shows whatever is there when the format row is missing", () => {
            expect(
                lengthLabel({ version_id: 1, page_count: 412, format: null }),
            ).toBe("412 pp");
            expect(lengthLabel(null)).toBe("");
        });

        it("shows both when a format declares both", () => {
            const both = copy({
                format: {
                    ...PHYSICAL,
                    expects_audio_runtime: true,
                },
                page_count: 412,
                audio_runtime: 1268,
            });

            expect(lengthLabel(both)).toBe("412 pp · 21h 8m");
        });
    });

    describe("copy labels", () => {
        it("names a copy by nickname, falling back to its format", () => {
            expect(copyLabel(copy({ nickname: "Ace paperback" }))).toBe(
                "Ace paperback",
            );
            expect(copyLabel(copy({ nickname: "   " }))).toBe("Physical");
            expect(copyLabel(copy({ format: null }))).toBe("Unknown format");
            expect(formatLabel(copy({ format: AUDIO }))).toBe("Audiobook");
        });

        it("prefers a location's name over its code", () => {
            expect(
                locationLabel(copy({ location: shelf("Office shelf") })),
            ).toBe("Office shelf");
            expect(locationLabel(copy({ location: shelf(null) }))).toBe("O1S5");
            expect(locationLabel(copy())).toBe("");
        });

        it("reads the discard state from the flag, never the date", () => {
            // A copy got rid of at an unrecoverable date is still discarded.
            expect(copyState(copy({ is_discarded: true }))).toBe("discarded");
            expect(
                copyState(copy({ is_discarded: true, discarded_at: null })),
            ).toBe("discarded");
            expect(copyState(copy({ location: shelf("Office") }))).toBe(
                "shelved",
            );
            expect(copyState(copy())).toBe("unshelved");
        });

        it("keeps a discarded copy discarded even when a shelf lingers", () => {
            const stale = copy({
                is_discarded: true,
                location: shelf("Office"),
            });

            expect(copyState(stale)).toBe("discarded");
        });
    });

    describe("copy ordering and counts", () => {
        it("puts copies you still own first, keeping server order within", () => {
            const [a, b, c] = [
                copy({ version_id: 1, is_discarded: true }),
                copy({ version_id: 2 }),
                copy({ version_id: 3 }),
            ];

            expect(orderedCopies([a, b, c]).map((v) => v.version_id)).toEqual([
                2, 3, 1,
            ]);
        });

        it("counts the shelf and the discard pile separately", () => {
            const versions = [
                copy({ version_id: 1 }),
                copy({ version_id: 2, is_discarded: true }),
            ];

            expect(copyCounts(versions)).toEqual({
                total: 2,
                onShelf: 1,
                discarded: 1,
            });
            expect(copyCounts()).toEqual({
                total: 0,
                onShelf: 0,
                discarded: 0,
            });
        });

        it("summarizes copies, mentioning discards only when there are any", () => {
            expect(copySummary([])).toBe("No copies");
            expect(copySummary([copy()])).toBe("1 copy");
            expect(
                copySummary([copy({ version_id: 1 }), copy({ version_id: 2 })]),
            ).toBe("2 copies");
            expect(
                copySummary([
                    copy({ version_id: 1 }),
                    copy({ version_id: 2, is_discarded: true }),
                ]),
            ).toBe("1 copy · 1 discarded");
            expect(copySummary([copy({ is_discarded: true })])).toBe(
                "1 copy, all discarded",
            );
        });
    });

    describe("reads", () => {
        const threeReads = book({
            versions: [
                copy({ version_id: 1, nickname: "Ace paperback" }),
                copy({ version_id: 2, format: AUDIO, is_discarded: true }),
            ],
            reads: [
                read({
                    read_instance_id: 30,
                    date_read: "2022-03-12",
                    rating: 4.5,
                    version_id: 1,
                }),
                read({
                    read_instance_id: 20,
                    date_read: "2014-06-03",
                    rating: 4,
                    version_id: 2,
                }),
                read({
                    read_instance_id: 10,
                    date_read: "2008-06-01",
                    version_id: 1,
                }),
            ],
        });

        it("joins each read to the copy it happened on", () => {
            const rows = readsFor(threeReads);

            expect(rows.map((r) => r.copyLabel)).toEqual([
                "Ace paperback",
                "Audiobook",
                "Ace paperback",
            ]);
            expect(rows.map((r) => r.copyIsDiscarded)).toEqual([
                false,
                true,
                false,
            ]);
        });

        it("keeps the read instance's own key rather than a generic id", () => {
            expect(readsFor(threeReads).map((r) => r.read_instance_id)).toEqual(
                [30, 20, 10],
            );
        });

        it("labels each read with the gap since the previous one", () => {
            expect(readsFor(threeReads).map((r) => r.gap)).toEqual([
                "8 years later",
                "6 years later",
                "",
            ]);
        });

        it("treats a zero rating as never rated", () => {
            const rows = readsFor(
                book({ reads: [read({ rating: 0 }), read({ rating: 3.5 })] }),
            );

            expect(rows[0].rating).toBeNull();
            expect(rows[1].rating).toBe(3.5);
        });

        it("survives a read whose copy is not in the payload", () => {
            const rows = readsFor(
                book({ versions: [], reads: [read({ version_id: 77 })] }),
            );

            expect(rows[0].copy).toBeNull();
            expect(rows[0].copyLabel).toBe("");
        });

        it("counts reads per copy", () => {
            const counts = readsByCopy(threeReads);

            expect(counts.get(1)).toBe(2);
            expect(counts.get(2)).toBe(1);
            expect(counts.get(3)).toBeUndefined();
        });

        it("ignores a read with no copy when counting per copy", () => {
            expect(
                readsByCopy(book({ reads: [read({ version_id: null })] })).size,
            ).toBe(0);
        });
    });

    describe("reading summary", () => {
        it("averages only the reads that were rated", () => {
            // Most of the historical library was read long before ratings
            // existed; counting those as zero would sink every old favourite.
            const summary = readingSummary(
                book({
                    reads: [
                        read({ date_read: "2022-03-12", rating: 5 }),
                        read({ date_read: "2014-06-03", rating: 4 }),
                        read({ date_read: "2008-06-01" }),
                    ],
                }),
            );

            expect(summary).toEqual({
                count: 3,
                isUnread: false,
                rated: 2,
                average: 4.5,
                first: "2008-06-01",
                last: "2022-03-12",
            });
        });

        it("reports an unread book", () => {
            const summary = readingSummary(book());

            expect(summary.isUnread).toBe(true);
            expect(summary.count).toBe(0);
            expect(summary.average).toBeNull();
            expect(summary.first).toBeNull();
        });

        it("rounds an average to one place", () => {
            const summary = readingSummary(
                book({
                    reads: [
                        read({ rating: 4 }),
                        read({ rating: 4.5 }),
                        read({ rating: 5 }),
                    ],
                }),
            );

            expect(summary.average).toBe(4.5);
        });

        it("names small counts in words", () => {
            expect(timesRead(1)).toBe("once");
            expect(timesRead(2)).toBe("twice");
            expect(timesRead(3)).toBe("three times");
            expect(timesRead(9)).toBe("9 times");
        });
    });

    describe("reading line", () => {
        it("says so when a book has never been read", () => {
            expect(readingLine(book())).toBe("Unread");
        });

        it("does not call a single rating an average", () => {
            expect(
                readingLine(
                    book({
                        reads: [read({ date_read: "2022-03-12", rating: 4.5 })],
                    }),
                ),
            ).toBe("Read once · ★ 4.5 · Mar 2022");
        });

        it("gives a range across several reads", () => {
            expect(
                readingLine(
                    book({
                        reads: [
                            read({ date_read: "2022-03-12", rating: 5 }),
                            read({ date_read: "2014-06-03", rating: 4 }),
                            read({ date_read: "2008-06-01", rating: 4 }),
                        ],
                    }),
                ),
            ).toBe("Read three times · ★ 4.3 average · Jun 2008 – Mar 2022");
        });

        it("collapses a range whose ends are the same month", () => {
            expect(
                readingLine(
                    book({
                        reads: [
                            read({ date_read: "2022-03-28" }),
                            read({ date_read: "2022-03-02" }),
                        ],
                    }),
                ),
            ).toBe("Read twice · Mar 2022");
        });

        it("says the dates are unknown rather than showing a blank", () => {
            expect(readingLine(book({ reads: [read({ rating: 4 })] }))).toBe(
                "Read once · ★ 4, date unknown",
            );
            expect(readingLine(book({ reads: [read(), read()] }))).toBe(
                "Read twice, dates unknown",
            );
        });
    });

    describe("authors", () => {
        it("shapes every author so a missing slug is falsy, not undefined", () => {
            const rows = authorList(
                book({
                    authors: [
                        {
                            author_id: 1,
                            first_name: "Frank",
                            last_name: "Herbert",
                            slug: "herbert",
                        },
                        { author_id: 2, first_name: "", last_name: "" },
                    ],
                }),
            );

            expect(rows).toEqual([
                { author_id: 1, name: "Frank Herbert", slug: "herbert" },
                { author_id: 2, name: "Unknown author", slug: null },
            ]);
        });

        it("returns an empty list for a book with no authors", () => {
            expect(authorList(book())).toEqual([]);
            expect(authorList(null)).toEqual([]);
        });
    });

    describe("lists", () => {
        // Lists hold versions, not books, so a list can point at the audiobook
        // while you are looking at the paperback.
        const target = book({
            versions: [
                copy({ version_id: 1, nickname: "Ace paperback" }),
                copy({ version_id: 2, format: AUDIO }),
            ],
        });

        const list = (list_id, name, versionIds) => ({
            list_id,
            name,
            items: versionIds.map((version_id) => ({ version_id })),
        });

        it("keeps only the lists holding a copy of this book", () => {
            const rows = listsHolding(target, [
                list(1, "To reread", [2, 99]),
                list(2, "Someone else's", [99]),
            ]);

            expect(rows).toHaveLength(1);
            expect(rows[0].list.name).toBe("To reread");
            expect(rows[0].copies).toEqual(["Audiobook"]);
        });

        it("names every copy a list holds", () => {
            const rows = listsHolding(target, [list(1, "Both", [1, 2])]);

            expect(rows[0].copies).toEqual(["Ace paperback", "Audiobook"]);
        });

        it("handles a list with no items loaded", () => {
            expect(
                listsHolding(target, [{ list_id: 1, name: "Empty" }]),
            ).toEqual([]);
            expect(listsHolding(target)).toEqual([]);
        });
    });
});
