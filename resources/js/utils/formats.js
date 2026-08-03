/**
 * Format capability lookups.
 *
 * A format declares which length fields it carries (`expects_page_count`,
 * `expects_audio_runtime`) and the API ships those flags on every format —
 * both on `GET /api/config/formats` and on the `format` relation loaded with a
 * version. Read them through here instead of matching on `name` or on a
 * hardcoded `format_id`, so adding a format stays a database row.
 *
 * Every helper takes the format list because the flags may need resolving from
 * a bare `format_id` held by a <select>; when a component already has the
 * format object, pass it to `expects` directly.
 */

export function findFormat(formats, formatId) {
    if (!formats || formatId === null || formatId === undefined) return null;
    // Loose equality on purpose: a <select> yields the id as a string.
    /* eslint-disable-next-line eqeqeq */
    return formats.find((format) => format.format_id == formatId) ?? null;
}

/**
 * Unknown formats expect nothing, so a form renders no length field rather
 * than guessing at one while the config store is still loading.
 */
export function expects(format, field) {
    if (!format) return false;
    return field === "page_count"
        ? !!format.expects_page_count
        : !!format.expects_audio_runtime;
}

export function formatExpects(formats, formatId, field) {
    return expects(findFormat(formats, formatId), field);
}

export function expectsPageCount(formats, formatId) {
    return formatExpects(formats, formatId, "page_count");
}

export function expectsAudioRuntime(formats, formatId) {
    return formatExpects(formats, formatId, "audio_runtime");
}
