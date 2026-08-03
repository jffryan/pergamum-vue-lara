<script>
/**
 * Name-and-count pairs — genres on a list, ratings on a shelf.
 *
 * When `selectable`, each row emits `select`; the widget itself stays generic
 * and the surface's view decides what a selection means (a filtered table on
 * the list statistics page, a pivot elsewhere).
 */
export default {
    name: "BreakdownList",
    props: {
        items: {
            type: Array,
            default: () => [],
        },
        nameKey: {
            type: String,
            default: "name",
        },
        countKey: {
            type: String,
            default: "count",
        },
        selectable: {
            type: Boolean,
            default: false,
        },
        selected: {
            type: [String, Number],
            default: null,
        },
        emptyMessage: {
            type: String,
            default: "Nothing to break down yet.",
        },
    },
    emits: ["select"],
};
</script>

<template>
    <div>
        <p v-if="!items.length" class="text-sm text-zinc-300">
            {{ emptyMessage }}
        </p>
        <div v-else class="grid grid-cols-2 sm:grid-cols-3 gap-1">
            <button
                v-for="item in items"
                :key="item[nameKey]"
                type="button"
                class="text-sm capitalize text-left"
                :class="[
                    selectable ? 'hover:underline' : 'cursor-default',
                    selected === item[nameKey] ? 'font-semibold' : '',
                ]"
                @click="selectable && $emit('select', item[nameKey])"
            >
                {{ item[nameKey] }}
                <span class="text-zinc-400">({{ item[countKey] }})</span>
            </button>
        </div>
    </div>
</template>
