/**
 * One location's statistics — shelf, bookcase, or room, the same surface: the
 * backend's location scope reports over the whole subtree, so a room is just
 * a wider shelf here.
 *
 * Wording follows `listStatistics`: books dedupe by work, pages count every
 * copy, and the "all copies" qualifier keeps a reader from dividing one by
 * the other.
 */
export default {
    key: "locationStatistics",
    scope: { type: "location" },
    emptyMessage: "Nothing is shelved here yet.",
    emptyWhen: (metrics) => metrics.totalItems === 0,
    widgets: [
        {
            id: "totalItems",
            widget: "statTile",
            span: 3,
            tone: "accent",
            metrics: { value: "totalItems" },
            props: { label: "Books Here" },
        },
        {
            id: "completed",
            widget: "statTile",
            span: 3,
            tone: "accent",
            metrics: { value: "completedCount" },
            props: { label: "Books Completed" },
        },
        {
            id: "completedPercent",
            widget: "statTile",
            span: 3,
            metrics: { value: "completedPercent" },
            props: { label: "Completed", format: "percent" },
        },
        {
            id: "totalPages",
            widget: "statTile",
            span: 3,
            metrics: { value: "totalPages" },
            props: { label: "Total Pages (all copies)" },
        },
        {
            id: "genres",
            widget: "breakdownList",
            span: 8,
            heading: "Genres",
            metrics: { items: "genreBreakdown" },
            visibleWhen: (m) => (m.genreBreakdown ?? []).length > 0,
        },
        {
            id: "avgRating",
            widget: "statTile",
            span: 4,
            metrics: { value: "averageRating" },
            props: { label: "Avg. Rating (out of 5)", format: "rating" },
            visibleWhen: (m) =>
                m.averageRating !== null && m.averageRating !== undefined,
        },
    ],
};
