import { describe, it, expect } from "vitest";
import {
    copyPayload,
    emptyCopy,
    saveErrorMessage,
    validateCopy,
} from "@/utils/copyForm";

// `/config/formats` rows: the capability flags are what the form reads.
const formats = [
    {
        format_id: 1,
        name: "hardcover",
        expects_page_count: true,
        expects_audio_runtime: false,
    },
    {
        format_id: 2,
        name: "audio",
        expects_page_count: false,
        expects_audio_runtime: true,
    },
];

const copy = (overrides = {}) => ({
    ...emptyCopy(),
    format_id: 1,
    page_count: "320",
    ...overrides,
});

describe("emptyCopy", () => {
    it("starts unshelved with no format", () => {
        expect(emptyCopy()).toMatchObject({
            format_id: null,
            location_id: null,
        });
    });
});

describe("validateCopy", () => {
    it("passes a copy with a format and its length", () => {
        expect(validateCopy(copy(), formats)).toEqual({});
    });

    it("requires a format", () => {
        expect(validateCopy(copy({ format_id: null }), formats)).toHaveProperty(
            "format_id",
        );
    });

    it("requires only the length the format carries", () => {
        expect(validateCopy(copy({ page_count: "" }), formats)).toEqual({
            page_count: "Enter a page count.",
        });
        expect(
            validateCopy(
                copy({ format_id: 2, page_count: "", audio_runtime: "" }),
                formats,
            ),
        ).toEqual({ audio_runtime: "Enter a runtime in minutes." });
    });

    it("doesn't require a shelf or nickname", () => {
        expect(
            validateCopy(copy({ location_id: null, nickname: "" }), formats),
        ).toEqual({});
    });
});

describe("copyPayload", () => {
    it("builds the version row both create endpoints accept", () => {
        expect(
            copyPayload(
                copy({ nickname: " signed ", location_id: 7 }),
                formats,
            ),
        ).toEqual({
            format: { format_id: 1 },
            page_count: 320,
            audio_runtime: null,
            nickname: "signed",
            location_id: 7,
        });
    });

    it("sends an unshelved copy with a null location and nickname", () => {
        expect(copyPayload(copy(), formats)).toMatchObject({
            location_id: null,
            nickname: null,
        });
    });

    it("drops a length left over from a previously chosen format", () => {
        expect(
            copyPayload(
                copy({ format_id: 2, page_count: "300", audio_runtime: "95" }),
                formats,
            ),
        ).toMatchObject({ page_count: null, audio_runtime: 95 });
    });
});

describe("saveErrorMessage", () => {
    it("names what failed and surfaces the first field error of a 422", () => {
        const error = {
            response: {
                status: 422,
                data: {
                    errors: {
                        "version.location_id": [
                            "The selected location is invalid.",
                        ],
                    },
                },
            },
        };

        expect(saveErrorMessage(error, "copy")).toBe(
            "The copy couldn't be saved: The selected location is invalid.",
        );
    });

    it("falls back to a generic line otherwise", () => {
        expect(saveErrorMessage(new Error("Network Error"), "copy")).toBe(
            "Unable to save this copy. Please try again.",
        );
    });
});
