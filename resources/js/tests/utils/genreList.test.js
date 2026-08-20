import { describe, it, expect } from "vitest";
import {
    LETTERS,
    filterGenres,
    sortGenres,
    letterFor,
    groupByLetter,
    countScale,
} from "@/utils/genreList";

const genre = (genre_id, name, books_count) => ({
    genre_id,
    name,
    books_count,
});

const NONFICTION = genre(1, "nonfiction", 383);
const HISTORY = genre(2, "history", 188);
const BIOGRAPHY = genre(3, "biography", 39);
const HORROR = genre(4, "horror", 2);
const ESSAYS = genre(5, "essays", 2);
const TWENTIETH = genre(6, "20th century", 11);

const GENRES = [NONFICTION, HISTORY, BIOGRAPHY, HORROR, ESSAYS, TWENTIETH];

describe("utils/genreList", () => {
    describe("filterGenres", () => {
        it("matches a case-insensitive substring anywhere in the name", () => {
            expect(filterGenres(GENRES, "IST")).toEqual([HISTORY]);
        });

        it("returns everything for an empty or whitespace-only term", () => {
            expect(filterGenres(GENRES, "")).toEqual(GENRES);
            expect(filterGenres(GENRES, "   ")).toEqual(GENRES);
        });

        // The old implementation built a RegExp from the raw input, so a lone
        // "(" threw a SyntaxError out of a computed and blanked the view.
        it("treats regex metacharacters as literal text", () => {
            const punctuated = genre(7, "sci-fi (hard)", 4);

            expect(() => filterGenres(GENRES, "(")).not.toThrow();
            expect(filterGenres(GENRES, "(")).toEqual([]);
            expect(filterGenres([...GENRES, punctuated], "(hard)")).toEqual([
                punctuated,
            ]);
        });

        it("does not mutate or alias the source list", () => {
            const result = filterGenres(GENRES, "");

            expect(result).not.toBe(GENRES);
            expect(GENRES).toHaveLength(6);
        });
    });

    describe("sortGenres", () => {
        it("sorts by name ascending", () => {
            expect(sortGenres(GENRES, "name").map((g) => g.name)).toEqual([
                "20th century",
                "biography",
                "essays",
                "history",
                "horror",
                "nonfiction",
            ]);
        });

        it("sorts by book count descending, breaking ties by name", () => {
            expect(sortGenres(GENRES, "count").map((g) => g.name)).toEqual([
                "nonfiction",
                "history",
                "biography",
                "20th century",
                "essays",
                "horror",
            ]);
        });

        it("falls back to the default sort on an unrecognized key", () => {
            expect(sortGenres(GENRES, "popularity.asc")).toEqual(
                sortGenres(GENRES, "name"),
            );
        });

        it("treats a missing count as zero rather than sorting it randomly", () => {
            const untagged = { genre_id: 8, name: "zzz" };

            expect(sortGenres([untagged, HORROR], "count")).toEqual([
                HORROR,
                untagged,
            ]);
        });

        it("does not sort the source list in place", () => {
            sortGenres(GENRES, "count");

            expect(GENRES[0]).toBe(NONFICTION);
        });
    });

    describe("letterFor", () => {
        it("uppercases the initial", () => {
            expect(letterFor("horror")).toBe("H");
        });

        it("files anything not starting with a letter under #", () => {
            expect(letterFor("20th century")).toBe("#");
            expect(letterFor("“essays”")).toBe("#");
        });

        it("ignores leading whitespace", () => {
            expect(letterFor("  essays")).toBe("E");
        });
    });

    describe("groupByLetter", () => {
        const sections = groupByLetter(sortGenres(GENRES, "name"));

        it("emits one section per occupied letter, alphabetically", () => {
            expect(sections.map((s) => s.letter)).toEqual([
                "B",
                "E",
                "H",
                "N",
                "#",
            ]);
        });

        it("puts the non-alphabetic bucket last, matching the API ordering", () => {
            expect(sections[sections.length - 1]).toEqual({
                letter: "#",
                genres: [TWENTIETH],
            });
        });

        it("preserves the order it was given within a section", () => {
            expect(
                groupByLetter(sortGenres(GENRES, "count")).find(
                    (s) => s.letter === "H",
                ).genres,
            ).toEqual([HISTORY, HORROR]);
        });

        it("returns no sections for an empty list", () => {
            expect(groupByLetter([])).toEqual([]);
        });
    });

    describe("countScale", () => {
        it("gives the largest genre a full bar", () => {
            expect(countScale(GENRES)(383)).toBe(100);
        });

        // Linear would put 2/383 at well under 1%; the point of the sqrt scale
        // is that the tail stays distinguishable from the head.
        it("keeps the long tail visible without flattening the ranking", () => {
            const scale = countScale(GENRES);

            expect(scale(2)).toBeGreaterThanOrEqual(3);
            expect(scale(2)).toBeLessThan(scale(39));
            expect(scale(39)).toBeLessThan(scale(188));
        });

        it("returns zero width for an untagged genre", () => {
            expect(countScale(GENRES)(0)).toBe(0);
        });

        it("survives an empty list without dividing by zero", () => {
            expect(countScale([])(5)).toBe(0);
        });
    });

    describe("LETTERS", () => {
        it("is A–Z plus the catch-all bucket", () => {
            expect(LETTERS).toHaveLength(27);
            expect(LETTERS[0]).toBe("A");
            expect(LETTERS[26]).toBe("#");
        });
    });
});
