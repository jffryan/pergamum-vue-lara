<script>
import { formatValue } from "@/services/statistics/formatters";

/**
 * A year-by-year series as a plain list.
 *
 * The unglamorous stand-in for a chart, and deliberately so: the registry is
 * what makes a later `barChart` a drop-in — same config entry, same metric
 * key, a different component.
 */
export default {
    name: "SeriesList",
    props: {
        series: {
            type: Array,
            default: () => [],
        },
        xKey: {
            type: String,
            default: "year",
        },
        yKey: {
            type: String,
            default: "total",
        },
        format: {
            type: String,
            default: "number",
        },
        emptyMessage: {
            type: String,
            default: "Nothing recorded yet.",
        },
    },
    methods: {
        formatted(row) {
            return formatValue(row[this.yKey], this.format);
        },
    },
};
</script>

<template>
    <div>
        <p v-if="!series.length" class="text-sm text-zinc-300">
            {{ emptyMessage }}
        </p>
        <div v-else class="grid grid-cols-2 sm:block">
            <span
                v-for="row in series"
                :key="row[xKey]"
                class="block mb-2 text-sm sm:text-lg"
            >
                <span class="font-bold">{{ row[xKey] }}</span
                >:
                {{ formatted(row) }}
            </span>
        </div>
    </div>
</template>
