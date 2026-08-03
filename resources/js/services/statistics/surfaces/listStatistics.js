/**
 * One list's statistics. Same widgets as the user surfaces, different scope
 * and different wording.
 *
 * "Total Pages" carries a qualifier because it sits beside "Books on List" and
 * the two answer different questions: books dedupe by work, pages count every
 * copy. Without the qualifier a reader divides one by the other and gets a
 * number that means nothing.
 */
export default {
    key: "listStatistics",
    scope: { type: "list" },
    emptyMessage: "This list has no items yet.",
    emptyWhen: (metrics) => metrics.totalItems === 0,
    widgets: [
        {
            id: "totalItems",
            widget: "statTile",
            span: 3,
            tone: "accent",
            metrics: { value: "totalItems" },
            props: { label: "Books on List" },
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
            props: { label: "of List Completed", format: "percent" },
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
            props: { selectable: true },
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
