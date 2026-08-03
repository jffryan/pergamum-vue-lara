<script>
import { useStatisticsStore } from "@/stores";
import { scopeKey } from "@/stores/StatisticsStore";
import { getWidget } from "@/services/statistics/widgetRegistry";
import { footnoteFor } from "@/services/statistics/footnotes";
import AlertBox from "@/components/globals/alerts/AlertBox.vue";
import PageLoadingIndicator from "@/components/globals/loading/PageLoadingIndicator.vue";
import WidgetShell from "@/components/statistics/WidgetShell.vue";

/**
 * The host every statistics surface is rendered by.
 *
 * Give it a surface config and it derives the metric set from the widgets,
 * issues exactly one request for it, places each widget at its declared span,
 * and owns loading / error / empty for the whole surface. Adding a statistics
 * page is writing a config file.
 */
export default {
    name: "StatisticsGrid",
    components: {
        AlertBox,
        PageLoadingIndicator,
        WidgetShell,
    },
    props: {
        surface: {
            type: Object,
            required: true,
        },
        /**
         * Which list / author / genre — whatever the surface's scope needs
         * beyond its type.
         */
        scopeId: {
            type: [String, Number],
            default: null,
        },
        /**
         * Runtime props keyed by widget id, for the state a config can't hold
         * — which genre the view currently has selected, say.
         */
        widgetProps: {
            type: Object,
            default: () => ({}),
        },
    },
    emits: ["widget-event"],
    setup() {
        return { StatisticsStore: useStatisticsStore() };
    },
    computed: {
        scopeType() {
            return this.surface.scope?.type ?? "user";
        },
        cacheKey() {
            return scopeKey(this.scopeType, this.scopeId);
        },
        state() {
            return this.StatisticsStore.scopeState(this.cacheKey);
        },
        metrics() {
            return this.StatisticsStore.metricsFor(this.cacheKey);
        },
        meta() {
            return this.StatisticsStore.metaFor(this.cacheKey);
        },
        /**
         * Every metric any widget might need — including widgets currently
         * hidden, since `visibleWhen` needs the data to decide.
         */
        requestedMetrics() {
            const keys = this.surface.widgets.flatMap((entry) =>
                Object.values(entry.metrics ?? {}),
            );

            return Array.from(new Set(keys));
        },
        hasData() {
            return Object.keys(this.metrics).length > 0;
        },
        visibleWidgets() {
            return this.surface.widgets
                .filter(
                    (entry) =>
                        !entry.visibleWhen || entry.visibleWhen(this.metrics),
                )
                .map((entry) => ({
                    entry,
                    registered: getWidget(entry.widget),
                    failed: (this.meta.failed ?? []).some((key) =>
                        Object.values(entry.metrics ?? {}).includes(key),
                    ),
                    footnote: footnoteFor(entry, this.meta, this.metrics),
                }))
                .filter((widget) => widget.registered);
        },
        isEmpty() {
            if (!this.hasData) {
                return false;
            }

            // A surface can declare its own idea of empty — a list with no
            // items has metrics, they're just all zero, and six zeroes say
            // less than one sentence does.
            if (this.surface.emptyWhen) {
                return this.surface.emptyWhen(this.metrics);
            }

            return this.visibleWidgets.length === 0;
        },
    },
    watch: {
        scopeId() {
            this.load();
        },
    },
    async mounted() {
        await this.load();
    },
    methods: {
        async load() {
            try {
                await this.StatisticsStore.fetch(
                    this.scopeType,
                    this.scopeId,
                    this.requestedMetrics,
                );
            } catch (error) {
                // The store keeps the error on the scope; the template renders
                // it with a retry. Nothing else to do here.
            }
        },
        async retry() {
            try {
                await this.StatisticsStore.refresh(
                    this.scopeType,
                    this.scopeId,
                    this.requestedMetrics,
                );
            } catch (error) {
                // Same as above — a second failure re-renders the same alert.
            }
        },
        propsFor(widget) {
            return {
                ...widget.registered.propsFromMetrics(
                    this.metrics,
                    widget.entry,
                ),
                ...(widget.entry.props ?? {}),
                ...(this.widgetProps[widget.entry.id] ?? {}),
            };
        },
        onWidgetEvent(entry, name, payload) {
            this.$emit("widget-event", { widgetId: entry.id, name, payload });
        },
    },
};
</script>

<template>
    <div>
        <PageLoadingIndicator v-if="state.isLoading && !hasData" />

        <div v-else-if="state.error">
            <AlertBox
                message="Unable to load these statistics right now."
                alert-type="danger"
            />
            <button type="button" class="btn btn-primary mt-2" @click="retry">
                Try again
            </button>
        </div>

        <p v-else-if="isEmpty" class="text-gray-500">
            {{ surface.emptyMessage ?? "Nothing to report yet." }}
        </p>

        <div v-else class="bg-zinc-800 text-white rounded">
            <div class="grid grid-cols-12 gap-2 sm:gap-4 p-4">
                <WidgetShell
                    v-for="widget in visibleWidgets"
                    :key="widget.entry.id"
                    :span="widget.entry.span ?? 4"
                    :tall="widget.entry.tall ?? false"
                    :tone="widget.entry.tone ?? 'muted'"
                    :heading="widget.entry.heading ?? null"
                    :footnote="widget.footnote"
                    :unavailable="widget.failed"
                >
                    <component
                        :is="widget.registered.component"
                        v-bind="propsFor(widget)"
                        @select="onWidgetEvent(widget.entry, 'select', $event)"
                    />
                </WidgetShell>
            </div>
        </div>
    </div>
</template>
