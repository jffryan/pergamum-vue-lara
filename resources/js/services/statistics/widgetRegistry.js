import StatTile from "@/components/statistics/widgets/StatTile.vue";
import SeriesList from "@/components/statistics/widgets/SeriesList.vue";
import BreakdownList from "@/components/statistics/widgets/BreakdownList.vue";
import EntityLinkList from "@/components/statistics/widgets/EntityLinkList.vue";

/**
 * Widget key -> component.
 *
 * A surface config names a widget; this is where the name becomes something
 * renderable. Adding a chart later means one entry here and a `widget:` line
 * in a config — no backend change, no new fetch path.
 *
 * `propsFromMetrics` turns the entry's `metrics: { propName: metricKey }` map
 * into props. The default mapping covers every widget so far; a widget only
 * needs its own when a missing metric should become something other than
 * `undefined` (an empty list rather than a hole).
 */

const mapMetrics = (metrics, entry, fallbacks = {}) =>
    Object.fromEntries(
        Object.entries(entry.metrics ?? {}).map(([prop, key]) => [
            prop,
            metrics[key] ?? fallbacks[prop] ?? null,
        ]),
    );

const widgets = {
    statTile: {
        component: StatTile,
        propsFromMetrics: (metrics, entry) => mapMetrics(metrics, entry),
    },
    seriesList: {
        component: SeriesList,
        propsFromMetrics: (metrics, entry) =>
            mapMetrics(metrics, entry, { series: [] }),
    },
    breakdownList: {
        component: BreakdownList,
        propsFromMetrics: (metrics, entry) =>
            mapMetrics(metrics, entry, { items: [] }),
    },
    entityLinkList: {
        component: EntityLinkList,
        propsFromMetrics: (metrics, entry) =>
            mapMetrics(metrics, entry, { items: [] }),
    },
};

export const getWidget = (key) => widgets[key] ?? null;

export const widgetKeys = () => Object.keys(widgets);

export default widgets;
