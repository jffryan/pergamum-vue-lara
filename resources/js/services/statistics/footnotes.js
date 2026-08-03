import { formatNumber, formatDuration } from "./formatters";

/**
 * Caveats generated from the response's `meta`, not from prose in a widget.
 *
 * The backend declares which metrics are estimates and which are catalogue-wide;
 * the grid turns those declarations into a line of muted text under the widget.
 * Nothing here is hardcoded per metric, so a metric that stops being an estimate
 * stops being footnoted without anyone editing a component.
 */

const sumSeries = (series) =>
    Array.isArray(series)
        ? series.reduce((total, row) => total + (row.total ?? 0), 0)
        : null;

/**
 * "Includes ~1,900 pages estimated from 58h of audio."
 *
 * Falls back to naming the rate when the converted series wasn't requested —
 * the surface may show the estimate without its inputs.
 */
export const estimateFootnote = (metricKey, meta, metrics) => {
    const provenance = meta?.estimated?.[metricKey];

    if (!provenance) {
        return null;
    }

    const { pagesPerAudioMinute, converted } = provenance;
    const minutes = converted ? sumSeries(metrics?.[converted]) : null;

    if (minutes) {
        const pages = Math.round(minutes * pagesPerAudioMinute);

        return `Includes ~${formatNumber(pages)} pages estimated from ${formatDuration(minutes)} of audio.`;
    }

    return `Audio counts as pages at ~${Math.round(pagesPerAudioMinute * 60)} pages an hour.`;
};

/**
 * "Counts every book in the catalogue, not just yours." Only worth saying on a
 * surface whose other numbers are user-scoped.
 */
export const catalogWideFootnote = (metricKeys, meta) =>
    metricKeys.some((key) => meta?.catalogWide?.includes(key))
        ? "Counts the whole catalogue, not only your books."
        : null;

/**
 * The footnote for one widget entry, or null. Estimates win over scope notes:
 * a number that is part-guess earns the caveat more than one that is merely
 * broader than the page around it.
 *
 * A surface can override with `footnote: "…"` or opt out with `footnote: false`
 * — per-surface wording belongs to the surface, same as labels.
 */
export const footnoteFor = (entry, meta, metrics) => {
    if (entry.footnote === false) {
        return null;
    }

    if (typeof entry.footnote === "string") {
        return entry.footnote;
    }

    const keys = Object.values(entry.metrics ?? {});
    const estimate = keys
        .map((key) => estimateFootnote(key, meta, metrics))
        .find(Boolean);

    return estimate ?? catalogWideFootnote(keys, meta);
};

export default footnoteFor;
