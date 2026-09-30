/**
 * The new-book form's logic, kept out of the view so it can be tested without
 * a DOM: the empty draft, what makes a draft submittable, and the
 * `POST /api/create-book` payload it becomes.
 *
 * A draft is one book and the one copy the user has in hand — title, authors,
 * genres, format and length, where it's shelved, and optionally that they've
 * read it. More copies and reads are added from the book's page afterwards,
 * which is also where the payload's `versions` / `read_instances` arrays stop
 * being one-element.
 */
import { validateAuthor } from "@/utils/validators";
import {
    copyPayload,
    emptyCopy,
    saveErrorMessage,
    validateCopy,
} from "@/utils/copyForm";

// The display scale `App\Rules\Rating` accepts: 0.5 to 5 in half steps.
export const RATINGS = Array.from({ length: 10 }, (_, i) => (i + 1) / 2);

export function emptyAuthor() {
    return { first_name: "", last_name: "" };
}

export function emptyDraft() {
    return {
        title: "",
        authors: [emptyAuthor()],
        // `GenreTagInput` rows: `{ name, genre_id }`.
        genres: [],
        // Format, length, shelf, nickname — see `utils/copyForm.js`, shared
        // with the add-a-copy page.
        copy: emptyCopy(),
        read: {
            has_read: false,
            // `<input type="date">` value: `Y-m-d`, or "" for unknown.
            date_read: "",
            rating: null,
        },
    };
}

const trimmed = (value) => (typeof value === "string" ? value.trim() : "");

/**
 * The author rows that name someone, trimmed. A blank row is a form artifact
 * — the "add another" row nobody filled — not an error.
 */
export function namedAuthors(authors) {
    return (authors ?? []).filter(validateAuthor).map((author) => ({
        first_name: trimmed(author.first_name),
        last_name: trimmed(author.last_name),
    }));
}

/**
 * Whether one more author row may be added: only while the last row has
 * something in it, so the form never grows a stack of empty rows.
 */
export function canAddAuthor(authors) {
    const last = authors?.at(-1);

    return !last || validateAuthor(last);
}

/**
 * `{ field: message }` for every problem with the draft; empty when it can be
 * submitted. The rules mirror `CompleteBookCreationRequest` so the form
 * neither blocks a payload the API would take nor waves one through to a 422.
 */
export function validateDraft(draft, formats) {
    const errors = {};

    if (!trimmed(draft.title)) {
        errors.title = "Enter a title.";
    }

    if (!namedAuthors(draft.authors).length) {
        errors.authors = "Enter at least one author — a first or last name.";
    }

    return { ...errors, ...validateCopy(draft.copy, formats) };
}

/**
 * The `bookData` body for `POST /api/create-book`.
 *
 * The copy row is `copyPayload`'s. The read, when there is one, names no
 * `version_id`: the copy doesn't exist yet, and the server files a
 * version-less read against the payload's first — here, only — copy.
 */
export function toPayload(draft, formats) {
    const { read } = draft;

    return {
        book: { title: trimmed(draft.title) },
        authors: namedAuthors(draft.authors),
        genres: (draft.genres ?? []).map((genre) => ({ name: genre.name })),
        versions: [copyPayload(draft.copy, formats)],
        read_instances: read.has_read
            ? [
                  {
                      date_read: read.date_read || null,
                      rating: read.rating ?? null,
                  },
              ]
            : [],
    };
}

/**
 * "Sylvia Plath, Ted Hughes" for a title-check match — what tells two books
 * sharing a title apart.
 */
export function matchAuthorLine(match) {
    return (
        (match?.authors ?? [])
            .map((author) =>
                [author.first_name, author.last_name].filter(Boolean).join(" "),
            )
            .join(", ") || "no authors listed"
    );
}

export function submitErrorMessage(error) {
    return saveErrorMessage(error, "book");
}
