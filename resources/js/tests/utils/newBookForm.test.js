import { describe, it, expect } from "vitest";
import {
    RATINGS,
    canAddAuthor,
    emptyDraft,
    matchAuthorLine,
    namedAuthors,
    submitErrorMessage,
    toPayload,
    validateDraft,
} from "@/utils/newBookForm";

// `/config/formats` rows: the capability flags are what the form reads.
const hardcover = {
    format_id: 1,
    name: "hardcover",
    expects_page_count: true,
    expects_audio_runtime: false,
};
const audiobook = {
    format_id: 2,
    name: "audio",
    expects_page_count: false,
    expects_audio_runtime: true,
};
const formats = [hardcover, audiobook];

// A draft that passes validation, with overrides merged one level deep.
const draft = ({ copy = {}, read = {}, ...rest } = {}) => {
    const base = emptyDraft();

    return {
        ...base,
        title: "Project Hail Mary",
        authors: [{ first_name: "Andy", last_name: "Weir" }],
        ...rest,
        copy: { ...base.copy, format_id: 1, page_count: "476", ...copy },
        read: { ...base.read, ...read },
    };
};

describe("emptyDraft", () => {
    it("starts with one blank author row and an unshelved, unread copy", () => {
        const empty = emptyDraft();

        expect(empty.authors).toEqual([{ first_name: "", last_name: "" }]);
        expect(empty.copy.location_id).toBeNull();
        expect(empty.read.has_read).toBe(false);
    });

    it("hands out a fresh object each time", () => {
        const a = emptyDraft();
        a.authors.push({ first_name: "x", last_name: "" });

        expect(emptyDraft().authors).toHaveLength(1);
    });
});

describe("RATINGS", () => {
    it("is the server's scale: 0.5 to 5 in half steps", () => {
        expect(RATINGS[0]).toBe(0.5);
        expect(RATINGS.at(-1)).toBe(5);
        expect(RATINGS).toHaveLength(10);
    });
});

describe("namedAuthors", () => {
    it("drops blank rows and trims the rest", () => {
        expect(
            namedAuthors([
                { first_name: "  Ursula ", last_name: " Le Guin" },
                { first_name: "", last_name: "  " },
                { first_name: "Plato", last_name: "" },
            ]),
        ).toEqual([
            { first_name: "Ursula", last_name: "Le Guin" },
            { first_name: "Plato", last_name: "" },
        ]);
    });
});

describe("canAddAuthor", () => {
    it("allows another row only once the last one names someone", () => {
        expect(canAddAuthor([{ first_name: "", last_name: "" }])).toBe(false);
        expect(canAddAuthor([{ first_name: "", last_name: "Weir" }])).toBe(
            true,
        );
    });
});

describe("validateDraft", () => {
    it("passes a complete draft", () => {
        expect(validateDraft(draft(), formats)).toEqual({});
    });

    it("requires a title that isn't just whitespace", () => {
        expect(validateDraft(draft({ title: "   " }), formats)).toHaveProperty(
            "title",
        );
    });

    it("requires at least one named author", () => {
        const errors = validateDraft(
            draft({ authors: [{ first_name: " ", last_name: "" }] }),
            formats,
        );

        expect(errors).toHaveProperty("authors");
    });

    it("does not require genres", () => {
        expect(validateDraft(draft({ genres: [] }), formats)).toEqual({});
    });

    it("requires a format", () => {
        expect(
            validateDraft(draft({ copy: { format_id: null } }), formats),
        ).toHaveProperty("format_id");
    });

    it("requires the length field the format carries, and only that one", () => {
        expect(
            validateDraft(draft({ copy: { page_count: "" } }), formats),
        ).toHaveProperty("page_count");

        const audio = validateDraft(
            draft({
                copy: { format_id: 2, page_count: "", audio_runtime: "" },
            }),
            formats,
        );
        expect(audio).toHaveProperty("audio_runtime");
        expect(audio).not.toHaveProperty("page_count");
    });

    it("does not require a date or rating on a read", () => {
        expect(
            validateDraft(draft({ read: { has_read: true } }), formats),
        ).toEqual({});
    });
});

describe("toPayload", () => {
    it("builds the create-book body with one copy and no read", () => {
        const payload = toPayload(
            draft({
                title: "  Project Hail Mary ",
                genres: [{ name: "science fiction", genre_id: 3 }],
                copy: { nickname: "  signed ", location_id: 9 },
            }),
            formats,
        );

        expect(payload).toEqual({
            book: { title: "Project Hail Mary" },
            authors: [{ first_name: "Andy", last_name: "Weir" }],
            genres: [{ name: "science fiction" }],
            versions: [
                {
                    format: { format_id: 1 },
                    page_count: 476,
                    audio_runtime: null,
                    nickname: "signed",
                    location_id: 9,
                },
            ],
            read_instances: [],
        });
    });

    it("sends an unshelved copy with a null location", () => {
        const [version] = toPayload(draft(), formats).versions;

        expect(version.location_id).toBeNull();
        expect(version.nickname).toBeNull();
    });

    it("drops a length left over from a previously chosen format", () => {
        const [version] = toPayload(
            draft({
                copy: {
                    format_id: "2",
                    page_count: "300",
                    audio_runtime: "610",
                },
            }),
            formats,
        ).versions;

        expect(version.format).toEqual({ format_id: 2 });
        expect(version.page_count).toBeNull();
        expect(version.audio_runtime).toBe(610);
    });

    it("includes the read, with an unknown date sent as null", () => {
        const payload = toPayload(
            draft({ read: { has_read: true, date_read: "", rating: 4.5 } }),
            formats,
        );

        expect(payload.read_instances).toEqual([
            { date_read: null, rating: 4.5 },
        ]);
    });

    it("leaves the read out when the box is unticked, whatever was typed", () => {
        const payload = toPayload(
            draft({
                read: { has_read: false, date_read: "2026-01-15", rating: 3 },
            }),
            formats,
        );

        expect(payload.read_instances).toEqual([]);
    });
});

describe("matchAuthorLine", () => {
    it("joins each author's names", () => {
        expect(
            matchAuthorLine({
                authors: [
                    { first_name: "Sylvia", last_name: "Plath" },
                    { first_name: "Plato", last_name: null },
                ],
            }),
        ).toBe("Sylvia Plath, Plato");
    });

    it("says so when a match has no authors", () => {
        expect(matchAuthorLine({ authors: [] })).toBe("no authors listed");
    });
});

describe("submitErrorMessage", () => {
    it("surfaces the first field error of a 422", () => {
        const error = {
            response: {
                status: 422,
                data: {
                    errors: {
                        "bookData.versions.0.location_id": [
                            "The selected location is invalid.",
                        ],
                    },
                },
            },
        };

        expect(submitErrorMessage(error)).toBe(
            "The book couldn't be saved: The selected location is invalid.",
        );
    });

    it("falls back to a generic line for anything else", () => {
        expect(submitErrorMessage({ response: { status: 500 } })).toBe(
            "Unable to save this book. Please try again.",
        );
        expect(submitErrorMessage(new Error("Network Error"))).toBe(
            "Unable to save this book. Please try again.",
        );
    });
});
