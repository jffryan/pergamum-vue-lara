/**
 * The post-login landing surface: the same user scope as `userStatistics`,
 * cut to what's worth seeing at a glance.
 *
 * Because `StatisticsStore` caches per scope, arriving here and then opening
 * the full statistics page costs only the metrics this set didn't already
 * pull. Two surfaces, shared widgets, different configs — that's the seam.
 */
export default {
    key: "userDashboard",
    scope: { type: "user" },
    emptyMessage: "Add a book and this fills in.",
    widgets: [
        {
            id: "uniqueRead",
            widget: "statTile",
            span: 3,
            tone: "accent",
            metrics: { value: "totalBooksRead" },
            props: { label: "Books Read" },
        },
        {
            id: "totalReads",
            widget: "statTile",
            span: 3,
            tone: "accent",
            metrics: { value: "totalReads" },
            props: { label: "Total Reads" },
        },
        {
            id: "percentRead",
            widget: "statTile",
            span: 3,
            metrics: { value: "percentageOfBooksRead" },
            props: { label: "Of Catalog Read", format: "percent" },
        },
        {
            id: "avgRating",
            widget: "statTile",
            span: 3,
            metrics: { value: "averageRating" },
            props: { label: "Avg. Rating", format: "rating" },
            visibleWhen: (m) =>
                m.averageRating !== null && m.averageRating !== undefined,
        },
        {
            id: "readsByYear",
            widget: "seriesList",
            span: 6,
            heading: "Reads Per Year",
            metrics: { series: "readsByYear" },
        },
        {
            id: "newestBooks",
            widget: "entityLinkList",
            span: 6,
            heading: "Newest Books",
            metrics: { items: "newestBooks" },
        },
    ],
};
