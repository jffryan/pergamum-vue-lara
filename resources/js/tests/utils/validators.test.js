import { describe, it, expect } from "vitest";
import {
    validateAuthor,
    validateNumber,
    validateString,
    validateVersionLength,
} from "@/utils/validators";

describe("validateString", () => {
    it("accepts a non-empty string and rejects everything else", () => {
        expect(validateString("Plato")).toBe(true);
        expect(validateString("")).toBe(false);
        expect(validateString(null)).toBe(false);
        expect(validateString(undefined)).toBe(false);
        expect(validateString(12)).toBe(false);
    });
});

describe("validateNumber", () => {
    it("accepts numbers only — a numeric string is not a number", () => {
        expect(validateNumber(320)).toBe(true);
        expect(validateNumber("320")).toBe(false);
    });
});

// Page count and audio runtime have to answer this the same way. They didn't:
// the create form checked audio runtime with `validateNumber` alone, and the
// input that feeds it is `type="text"`, so every filled audiobook was invalid.
describe("validateVersionLength", () => {
    it("accepts the digit string the text inputs produce", () => {
        expect(validateVersionLength("320")).toBe(true);
    });

    it("accepts the number the API returns on an edit load", () => {
        expect(validateVersionLength(320)).toBe(true);
    });

    it("rejects an empty field", () => {
        expect(validateVersionLength("")).toBe(false);
        expect(validateVersionLength(null)).toBe(false);
        expect(validateVersionLength(undefined)).toBe(false);
    });
});

// The client half of the rule in
// `App\Http\Requests\Concerns\ValidatesAuthorNames`. If these two disagree,
// the form either blocks a payload the API would accept or waves through one
// it 422s — which is exactly what happened while the forms required a last
// name and the CSV importer did not.
describe("validateAuthor", () => {
    it("accepts an author with both names", () => {
        expect(
            validateAuthor({ first_name: "Hannah", last_name: "Arendt" }),
        ).toBe(true);
    });

    it("accepts a mononym, whichever half it is typed into", () => {
        expect(validateAuthor({ first_name: "Plato", last_name: "" })).toBe(
            true,
        );
        expect(validateAuthor({ first_name: "", last_name: "Aristotle" })).toBe(
            true,
        );
    });

    it("accepts an organization in the first-name field", () => {
        expect(
            validateAuthor({
                first_name: "National Geographic",
                last_name: "",
            }),
        ).toBe(true);
    });

    it("rejects an author with neither name", () => {
        expect(validateAuthor({ first_name: "", last_name: "" })).toBe(false);
        expect(validateAuthor({})).toBe(false);
    });

    it("treats a whitespace-only name as absent", () => {
        expect(validateAuthor({ first_name: "   ", last_name: "\t" })).toBe(
            false,
        );
        expect(validateAuthor({ first_name: " Plato ", last_name: "  " })).toBe(
            true,
        );
    });
});
