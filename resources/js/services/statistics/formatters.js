/**
 * Display formatting for metric values.
 *
 * Formatters are named in surface configs (`format: "percent"`), never in the
 * widgets, so the same widget can render a count on one surface and a
 * percentage on another. Metrics arrive ready to display — ratings are already
 * halved server-side — so nothing here does arithmetic on meaning.
 */

export const EMPTY = "—";

export const formatNumber = (value) => Number(value).toLocaleString();

export const formatPercent = (value) => `${Number(value)}%`;

export const formatRating = (value) => Number(value).toFixed(1);

/**
 * Whole minutes to "58h 20m" — `versions.audio_runtime` is stored in minutes
 * and no one reads a listening year in minutes.
 */
export const formatDuration = (minutes) => {
    const total = Math.round(Number(minutes));
    const hours = Math.floor(total / 60);
    const rest = total % 60;

    if (!hours) {
        return `${rest}m`;
    }

    return rest ? `${hours}h ${rest}m` : `${hours}h`;
};

/**
 * Thousands as "12.3k", for tiles too narrow for the full number.
 */
export const formatCompact = (value) => {
    const number = Number(value);

    if (Math.abs(number) < 1000) {
        return `${number}`;
    }

    return `${(number / 1000).toFixed(1).replace(/\.0$/, "")}k`;
};

const formatters = {
    number: formatNumber,
    percent: formatPercent,
    rating: formatRating,
    duration: formatDuration,
    compact: formatCompact,
};

export const formatValue = (value, format = "number") => {
    if (value === null || value === undefined || value === "") {
        return EMPTY;
    }

    return (formatters[format] ?? formatNumber)(value);
};

export default formatters;
