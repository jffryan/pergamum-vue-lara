import { describe, it, expect } from "vitest";
import { authorName, filterAuthors } from "@/utils/authorList";

const leGuin = {
    author_id: 1,
    first_name: "Ursula K.",
    last_name: "Le Guin",
    slug: "ursula-k-le-guin",
};
const aristotle = {
    author_id: 2,
    first_name: "Aristotle",
    last_name: "",
    slug: "aristotle",
};
const abecassis = {
    author_id: 3,
    first_name: "Eliette",
    last_name: "Abécassis",
    slug: "eliette-abecassis",
};
const authors = [leGuin, aristotle, abecassis];

describe("authorName", () => {
    it("joins both halves", () => {
        expect(authorName(leGuin)).toBe("Ursula K. Le Guin");
    });

    it("renders a mononym without a trailing space", () => {
        expect(authorName(aristotle)).toBe("Aristotle");
    });

    it("tolerates null halves and a missing author", () => {
        expect(authorName({ first_name: null, last_name: "Hooks" })).toBe(
            "Hooks",
        );
        expect(authorName(undefined)).toBe("");
    });
});

describe("filterAuthors", () => {
    it("returns a copy of everything for a blank term", () => {
        const result = filterAuthors(authors, "  ");
        expect(result).toEqual(authors);
        expect(result).not.toBe(authors);
    });

    it("matches across the two halves, case-insensitively", () => {
        expect(filterAuthors(authors, "k. le")).toEqual([leGuin]);
    });

    it("ignores accents in either direction", () => {
        expect(filterAuthors(authors, "abecassis")).toEqual([abecassis]);
        expect(filterAuthors(authors, "ABÉC")).toEqual([abecassis]);
    });

    it("matches the slug, so a pasted URL tail finds the author", () => {
        expect(filterAuthors(authors, "ursula-k")).toEqual([leGuin]);
    });

    it("treats regex metacharacters as text", () => {
        expect(() => filterAuthors(authors, "(")).not.toThrow();
        expect(filterAuthors(authors, "(")).toEqual([]);
    });
});
