/**
 * The full statistics page: everything the user scope can tell you.
 *
 * Labels live here rather than on the widget or the metric because they are
 * per-surface wording — the same `readsByYear` may want tighter phrasing on a
 * dense dashboard than it does on this page.
 */
export default {
    key: "userStatistics",
    scope: { type: "user" },
    emptyMessage: "Read something and this page fills up.",
    widgets: [
        {
            id: "uniqueRead",
            widget: "statTile",
            span: 3,
            tone: "accent",
            metrics: { value: "totalBooksRead" },
            props: { label: "Unique Books Read" },
        },
        {
            id: "totalReads",
            widget: "statTile",
            span: 3,
            tone: "accent",
            metrics: { value: "totalReads" },
            props: { label: "Total Reads (incl. re-reads)" },
        },
        {
            id: "totalBooks",
            widget: "statTile",
            span: 3,
            tone: "accent",
            metrics: { value: "totalBooks" },
            props: { label: "Total Books in Catalog" },
        },
        {
            id: "percentRead",
            widget: "statTile",
            span: 3,
            metrics: { value: "percentageOfBooksRead" },
            props: { label: "Percentage of Catalog Read", format: "percent" },
        },
        {
            id: "readsByYear",
            widget: "seriesList",
            span: 4,
            tall: true,
            heading: "Reads Per Year",
            metrics: { series: "readsByYear" },
        },
        {
            id: "pagesByYear",
            widget: "seriesList",
            span: 4,
            tall: true,
            heading: "Pages Read Per Year",
            metrics: { series: "pagesReadByYear" },
        },
        {
            id: "newestBooks",
            widget: "entityLinkList",
            span: 4,
            heading: "Newest Books",
            metrics: { items: "newestBooks" },
        },
        {
            id: "audioByYear",
            widget: "seriesList",
            span: 4,
            heading: "Listening Per Year",
            metrics: { series: "audioRuntimeByYear" },
            props: { format: "duration" },
            visibleWhen: (m) => (m.audioRuntimeByYear ?? []).length > 0,
        },
        {
            id: "estimatedPages",
            widget: "seriesList",
            span: 4,
            heading: "Pages Per Year, Audio Included",
            // The footnote naming the conversion is generated from
            // `meta.estimated`; nothing about it is written here.
            metrics: { series: "estimatedTotalPagesByYear" },
            visibleWhen: (m) => (m.audioRuntimeByYear ?? []).length > 0,
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
        {
            id: "ratingSpread",
            widget: "breakdownList",
            span: 4,
            heading: "Ratings",
            metrics: { items: "ratingDistribution" },
            props: { nameKey: "rating", countKey: "total" },
            visibleWhen: (m) => (m.ratingDistribution ?? []).length > 0,
        },
    ],
};
