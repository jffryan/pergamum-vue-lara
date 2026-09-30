/**
 * One copy being entered: format, the length that format carries, shelf and
 * nickname. Shared by the two pages that create a copy — the new-book page
 * (the copy in hand, alongside the book) and the add-a-copy page (another
 * copy of a book that exists) — and rendered by `components/books/CopyFields`.
 * Kept free of the DOM so its rules are unit-tested.
 */
import { validateVersionLength } from "@/utils/validators";
import { expectsAudioRuntime, expectsPageCount } from "@/utils/formats";

export function emptyCopy() {
    return {
        format_id: null,
        page_count: "",
        audio_runtime: "",
        nickname: "",
        // null is "unshelved" — the virtual location, not a row.
        location_id: null,
    };
}

/**
 * `{ field: message }` for every problem with the copy; empty when valid.
 * Mirrors the version rules both create requests apply: a format, and the
 * length field that format expects. A length the format doesn't carry has no
 * input on screen to fix it with, so it can't be missing.
 */
export function validateCopy(copy, formats) {
    const errors = {};

    if (!copy.format_id) {
        errors.format_id = "Choose a format.";
    }

    if (
        expectsPageCount(formats, copy.format_id) &&
        !validateVersionLength(copy.page_count)
    ) {
        errors.page_count = "Enter a page count.";
    }

    if (
        expectsAudioRuntime(formats, copy.format_id) &&
        !validateVersionLength(copy.audio_runtime)
    ) {
        errors.audio_runtime = "Enter a runtime in minutes.";
    }

    return errors;
}

const toCount = (value) =>
    value === "" || value === null || value === undefined
        ? null
        : Number(value);

/**
 * The version row both `POST /create-book` and `POST /versions` accept.
 * Lengths the format doesn't carry go out as null — the server reduces them
 * the same way, but a stale page count from a previously chosen format
 * shouldn't even be sent.
 */
export function copyPayload(copy, formats) {
    const formatId = Number(copy.format_id);
    const nickname =
        typeof copy.nickname === "string" ? copy.nickname.trim() : "";

    return {
        format: { format_id: formatId },
        page_count: expectsPageCount(formats, formatId)
            ? toCount(copy.page_count)
            : null,
        audio_runtime: expectsAudioRuntime(formats, formatId)
            ? toCount(copy.audio_runtime)
            : null,
        nickname: nickname || null,
        location_id: copy.location_id ?? null,
    };
}

/**
 * The first message a failed save carries, for a form's alert. A 422 names
 * the offending field in `errors`; anything else falls back to a generic
 * line rather than showing the user a status code.
 */
export function saveErrorMessage(error, what) {
    const data = error?.response?.data;
    const firstFieldError = Object.values(data?.errors ?? {})[0]?.[0];

    if (error?.response?.status === 422 && firstFieldError) {
        return `The ${what} couldn't be saved: ${firstFieldError}`;
    }

    return `Unable to save this ${what}. Please try again.`;
}
