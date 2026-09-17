<script setup>
import { computed } from "vue";
import { GAP, PAGE_SIZES, pageWindow } from "@/utils/libraryList";

const props = defineProps({
    // The `pagination` block of a `GET /books` response.
    pagination: {
        type: Object,
        required: true,
    },
    pageSize: {
        type: Number,
        required: true,
    },
    // Builds the route for a page number. Pages are links rather than
    // buttons so they're bookmarkable and open in a new tab like any other.
    linkFor: {
        type: Function,
        required: true,
    },
});

const emit = defineEmits(["page-size"]);

const current = computed(() => props.pagination.currentPage);
const last = computed(() => props.pagination.lastPage);
const items = computed(() => pageWindow(current.value, last.value));

const range = computed(() => {
    const { from, to, total } = props.pagination;

    if (!total) {
        return "";
    }

    return from === 1 && to === total
        ? `Showing all ${total}`
        : `Showing ${from}–${to} of ${total}`;
});

// Only worth offering when a smaller size would paginate — a 12-book result
// paginates the same way at every size.
const offerSizes = computed(() => props.pagination.total > PAGE_SIZES[0]);
</script>

<template>
    <nav
        v-if="range"
        class="mt-6 flex flex-wrap items-center justify-between gap-x-6 gap-y-3 text-sm"
        aria-label="Pagination"
    >
        <span class="text-zinc-500">{{ range }}</span>

        <ul v-if="items.length" class="flex flex-wrap items-center gap-1">
            <li>
                <router-link
                    v-if="current > 1"
                    :to="linkFor(current - 1)"
                    class="rounded-md px-2 py-1 hover:bg-zinc-200"
                    rel="prev"
                >
                    &larr; Previous
                </router-link>
                <span v-else class="px-2 py-1 text-zinc-300">
                    &larr; Previous
                </span>
            </li>
            <li v-for="(item, index) in items" :key="`${index}-${item}`">
                <span
                    v-if="item === GAP"
                    class="inline-block w-6 text-center text-zinc-400"
                    aria-hidden="true"
                >
                    {{ item }}
                </span>
                <router-link
                    v-else
                    :to="linkFor(item)"
                    class="inline-block min-w-[2rem] rounded-md px-2 py-1 text-center tabular-nums"
                    :class="
                        item === current
                            ? 'bg-slate-900 text-white'
                            : 'hover:bg-zinc-200'
                    "
                    :aria-current="item === current ? 'page' : null"
                >
                    {{ item }}
                </router-link>
            </li>
            <li>
                <router-link
                    v-if="current < last"
                    :to="linkFor(current + 1)"
                    class="rounded-md px-2 py-1 hover:bg-zinc-200"
                    rel="next"
                >
                    Next &rarr;
                </router-link>
                <span v-else class="px-2 py-1 text-zinc-300">
                    Next &rarr;
                </span>
            </li>
        </ul>

        <label v-if="offerSizes" class="flex items-center gap-2 text-zinc-500">
            Per page
            <select
                :value="pageSize"
                class="rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm text-black"
                @change="emit('page-size', Number($event.target.value))"
            >
                <option v-for="size in PAGE_SIZES" :key="size" :value="size">
                    {{ size }}
                </option>
            </select>
        </label>
    </nav>
</template>
