<script setup>
import { ref, watch } from "vue";
import debounce from "lodash/debounce";
import { SORT_OPTIONS, sortOption } from "@/utils/libraryList";
import UpArrow from "@/components/globals/svgs/UpArrow.vue";

const props = defineProps({
    // The listing's committed state, as read back off the URL by the view:
    // `{ search, sort, direction, discarded, read, format }`.
    state: {
        type: Object,
        required: true,
    },
    // `/config/formats` rows. The format control only appears once there is a
    // choice to make.
    formats: {
        type: Array,
        default: () => [],
    },
});

// Every control emits the same thing: the query keys it wants changed. The
// view owns the URL and the fetch, so this component never knows whether a
// change was pushed, replaced or debounced away.
const emit = defineEmits(["navigate"]);

// Chip groups, one per query parameter. Adding a third axis is one more entry
// here — the template loops over the registry, not over named groups.
const CHIP_FILTERS = [
    {
        label: "Shelf",
        param: "discarded",
        options: [
            { value: "", label: "On the shelf" },
            { value: "only", label: "Discarded" },
        ],
    },
    {
        label: "Status",
        param: "read",
        options: [
            { value: "", label: "Any" },
            { value: "unread", label: "Unread" },
            { value: "read", label: "Read" },
        ],
    },
];

// Typing is local until it settles: the URL is the committed term, and
// pushing every keystroke into history would make the back button a
// backspace key. A settled term *replaces* the entry; Enter commits at once.
const term = ref(props.state.search);

const commitSearch = (replace) => {
    if (term.value.trim() === props.state.search) {
        return;
    }

    emit("navigate", { search: term.value.trim() }, { replace });
};

const settleSearch = debounce(() => commitSearch(true), 300);

const submitSearch = () => {
    settleSearch.cancel();
    commitSearch(false);
};

const clearSearch = () => {
    settleSearch.cancel();
    term.value = "";
    commitSearch(false);
};

watch(term, () => settleSearch());

// Keep the box in step when the term changes from outside it — a back
// navigation, or the view's clear-everything link.
watch(
    () => props.state.search,
    (search) => {
        if (search !== term.value.trim()) {
            settleSearch.cancel();
            term.value = search;
        }
    },
);

// Picking a sort fresh gives its natural direction — best-rated first, not
// worst — rather than carrying over whichever way the last sort ran.
const chooseSort = (event) => {
    const option = sortOption(event.target.value);

    emit("navigate", { sort: option.key, direction: option.direction });
};

const flipDirection = () => {
    emit("navigate", {
        direction: props.state.direction === "asc" ? "desc" : "asc",
    });
};

const chooseFormat = (event) => {
    emit("navigate", { format: event.target.value });
};
</script>

<template>
    <div
        class="sticky top-12 z-30 -mx-1 mb-4 border-b border-zinc-200 bg-zinc-50 px-1 py-3"
    >
        <div class="flex flex-wrap items-center gap-x-6 gap-y-3">
            <form
                class="relative grow max-w-sm"
                role="search"
                @submit.prevent="submitSearch"
            >
                <label for="library-search" class="sr-only">
                    Search titles and authors
                </label>
                <input
                    id="library-search"
                    v-model="term"
                    type="text"
                    placeholder="Search titles and authors…"
                    autocomplete="off"
                    class="!pr-8"
                />
                <button
                    v-if="term"
                    type="button"
                    class="absolute inset-y-0 right-0 px-2 text-zinc-400 hover:text-zinc-700"
                    aria-label="Clear search"
                    @click="clearSearch"
                >
                    ×
                </button>
            </form>

            <div class="flex items-center gap-2">
                <label for="library-sort" class="text-sm text-zinc-500">
                    Sort
                </label>
                <select
                    id="library-sort"
                    :value="state.sort"
                    class="rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm"
                    @change="chooseSort"
                >
                    <option
                        v-for="option in SORT_OPTIONS"
                        :key="option.key"
                        :value="option.key"
                    >
                        {{ option.label }}
                    </option>
                </select>
                <button
                    type="button"
                    class="rounded-md border border-zinc-300 bg-white p-1 hover:border-zinc-500"
                    :aria-label="
                        state.direction === 'asc'
                            ? 'Ascending — switch to descending'
                            : 'Descending — switch to ascending'
                    "
                    :title="
                        state.direction === 'asc' ? 'Ascending' : 'Descending'
                    "
                    @click="flipDirection"
                >
                    <UpArrow
                        class="h-4 w-4"
                        :class="{ 'rotate-180': state.direction === 'desc' }"
                        aria-hidden="true"
                    />
                </button>
            </div>
        </div>

        <div class="mt-3 flex flex-wrap items-center gap-x-6 gap-y-2">
            <div
                v-for="filter in CHIP_FILTERS"
                :key="filter.param"
                class="flex items-center gap-1"
                role="group"
                :aria-label="filter.label"
            >
                <span class="mr-1 text-sm text-zinc-500">
                    {{ filter.label }}
                </span>
                <button
                    v-for="option in filter.options"
                    :key="option.value"
                    type="button"
                    class="rounded-md border px-3 py-1 text-sm"
                    :class="
                        state[filter.param] === option.value
                            ? 'border-slate-900 bg-slate-900 text-white'
                            : 'border-zinc-300 hover:border-zinc-500'
                    "
                    :aria-pressed="state[filter.param] === option.value"
                    @click="emit('navigate', { [filter.param]: option.value })"
                >
                    {{ option.label }}
                </button>
            </div>

            <div v-if="formats.length > 1" class="flex items-center gap-2">
                <label for="library-format" class="text-sm text-zinc-500">
                    Format
                </label>
                <select
                    id="library-format"
                    :value="state.format"
                    class="rounded-md border border-zinc-300 bg-white px-2 py-1 text-sm"
                    @change="chooseFormat"
                >
                    <option value="">Any</option>
                    <option
                        v-for="format in formats"
                        :key="format.format_id"
                        :value="format.name"
                    >
                        {{ format.name }}
                    </option>
                </select>
            </div>
        </div>
    </div>
</template>
