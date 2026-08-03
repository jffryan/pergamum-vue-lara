import { describe, it, expect } from "vitest";
import {
    findFormat,
    expects,
    formatExpects,
    expectsPageCount,
    expectsAudioRuntime,
} from "@/utils/formats";

const AUDIOBOOK = {
    format_id: 2,
    name: "Audiobook",
    expects_page_count: false,
    expects_audio_runtime: true,
};

const PHYSICAL = {
    format_id: 1,
    name: "Physical",
    expects_page_count: true,
    expects_audio_runtime: false,
};

const FORMATS = [PHYSICAL, AUDIOBOOK];

describe("utils/formats", () => {
    describe("findFormat", () => {
        it("resolves a format by id", () => {
            expect(findFormat(FORMATS, 2)).toBe(AUDIOBOOK);
        });

        it("resolves a string id, because a <select> yields one", () => {
            expect(findFormat(FORMATS, "2")).toBe(AUDIOBOOK);
        });

        it("returns null for an unknown id", () => {
            expect(findFormat(FORMATS, 99)).toBeNull();
        });

        it("returns null when no format has been selected yet", () => {
            expect(findFormat(FORMATS, null)).toBeNull();
            expect(findFormat(FORMATS, undefined)).toBeNull();
        });

        it("returns null before the config store has loaded", () => {
            expect(findFormat(undefined, 2)).toBeNull();
            expect(findFormat([], 2)).toBeNull();
        });
    });

    describe("expects", () => {
        it("reads the capability off a format object", () => {
            expect(expects(AUDIOBOOK, "audio_runtime")).toBe(true);
            expect(expects(AUDIOBOOK, "page_count")).toBe(false);
            expect(expects(PHYSICAL, "page_count")).toBe(true);
            expect(expects(PHYSICAL, "audio_runtime")).toBe(false);
        });

        // An unresolved format must not render a field a user cannot clear.
        it("expects nothing of a missing format", () => {
            expect(expects(null, "page_count")).toBe(false);
            expect(expects(undefined, "audio_runtime")).toBe(false);
        });

        it("coerces a missing flag to false rather than undefined", () => {
            expect(
                expects({ format_id: 7, name: "Legacy" }, "page_count"),
            ).toBe(false);
        });
    });

    describe("formatExpects", () => {
        it("resolves the id then reads the capability", () => {
            expect(formatExpects(FORMATS, 2, "audio_runtime")).toBe(true);
            expect(formatExpects(FORMATS, 1, "audio_runtime")).toBe(false);
        });

        it("is false for an id no format claims", () => {
            expect(formatExpects(FORMATS, 99, "page_count")).toBe(false);
        });
    });

    describe("named helpers", () => {
        it("agree with formatExpects", () => {
            expect(expectsPageCount(FORMATS, 1)).toBe(true);
            expect(expectsPageCount(FORMATS, 2)).toBe(false);
            expect(expectsAudioRuntime(FORMATS, 2)).toBe(true);
            expect(expectsAudioRuntime(FORMATS, 1)).toBe(false);
        });
    });

    // The whole point of the refactor: nothing keys off the name or the id.
    it("does not care what a format is called", () => {
        const podcast = {
            format_id: 42,
            name: "Podcast",
            expects_page_count: false,
            expects_audio_runtime: true,
        };

        expect(formatExpects([podcast], 42, "audio_runtime")).toBe(true);
        expect(formatExpects([podcast], 42, "page_count")).toBe(false);
    });
});
