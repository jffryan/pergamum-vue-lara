<script setup>
import { computed, onMounted, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { getAllBooks } from "@/api/BookController";
import { useBooksStore, useConfigStore } from "@/stores";
import {
    DEFAULT_SORT,
    DEFAULT_PAGE_SIZE,
    PAGE_SIZES,
    resolveSort,
    sortOption,
    groupBooks,
    summarize,
} from "@/utils/libraryList";
import AlertBox from "@/components/globals/alerts/AlertBox.vue";
import PageLoadingIndicator from "@/components/globals/loading/PageLoadingIndicator.vue";
import LibraryBookRow from "@/components/library/LibraryBookRow.vue";
import LibraryPagination from "@/components/library/LibraryPagination.vue";
import LibraryToolbar from "@/components/library/LibraryToolbar.vue";

const route = useRoute();
const router = useRouter();
const booksStore = useBooksStore();
const configStore = useConfigStore();

// The listing is paginated server-side, so every control that changes what
// the query returns goes through the URL — the page, the sort, the search,
// each filter. The URL is the one source of truth; this is it, read back.
const state = computed(() => {
    const { key, direction } = resolveSort(route.query);
    const limit = Number(route.query.limit);

    return {
        page: Number(route.query.page) || 1,
        sort: key,
        direction,
        search: route.query.search || "",
        // Absent means "on the shelf" — the backend defaults to excluding
        // books whose every version has been discarded.
        discarded: route.query.discarded || "",
        read: route.query.read || "",
        format: route.query.format || "",
        limit: PAGE_SIZES.includes(limit) ? limit : DEFAULT_PAGE_SIZE,
    };
});

const showingDiscarded = computed(() => state.value.discarded === "only");

const requestOptions = computed(() => {
    const { page, sort, direction, limit, search, discarded, read, format } =
        state.value;
    const options = { page, sort, direction, limit };

    if (search) options.search = search;
    if (discarded) options.discarded = discarded;
    if (read) options.read = read;
    if (format) options.format = format;

    return options;
});

/**
 * The URL for the current state with some keys changed. Defaults stay out of
 * it so the common case is a clean `/library` and the back button doesn't
 * collect no-op entries — including the sort direction when it's the one the
 * sort implies, so `?sort=rating` stays `?sort=rating`.
 */
const buildQuery = (overrides = {}) => {
    const merged = { ...state.value, ...overrides };

    if (merged.direction === sortOption(merged.sort).direction) {
        delete merged.direction;
    }
    if (merged.sort === DEFAULT_SORT) delete merged.sort;
    if (merged.page === 1) delete merged.page;
    if (merged.limit === DEFAULT_PAGE_SIZE) delete merged.limit;

    return Object.fromEntries(
        Object.entries(merged).filter(
            ([, value]) => value !== "" && value !== undefined,
        ),
    );
};

// Every change but paging drops back to page 1: the row that was on page 4
// under one sort is somewhere else entirely under the next.
const navigate = (overrides, { replace = false } = {}) => {
    const location = {
        name: "library.index",
        query: buildQuery({ ...overrides, page: undefined }),
    };

    return replace ? router.replace(location) : router.push(location);
};

const linkFor = (page) => ({
    name: "library.index",
    query: buildQuery({ page }),
});

const clearFilters = () =>
    navigate({ search: "", discarded: "", read: "", format: "" });

const isFiltered = computed(() => {
    const { search, discarded, read, format } = state.value;

    return Boolean(search || discarded || read || format);
});

// --- Data -------------------------------------------------------------------

const hasLoaded = ref(false);
const isLoading = ref(true);
const error = ref("");
const pagination = ref(null);

const books = computed(() => booksStore.allBooks);
const sections = computed(() => groupBooks(books.value, state.value.sort));
const formats = computed(() => configStore.books.formats);

const summary = computed(() => {
    if (!pagination.value) {
        return "";
    }

    return summarize({
        total: pagination.value.total,
        search: state.value.search,
        read: state.value.read,
        discarded: showingDiscarded.value,
    });
});

const fetchData = async () => {
    isLoading.value = true;
    error.value = "";

    try {
        const res = await getAllBooks(requestOptions.value);

        if (!res.data || res.status !== 200) {
            throw new Error("Invalid response from the server");
        }

        booksStore.setAllBooks(res.data.books);
        pagination.value = res.data.pagination;
    } catch (e) {
        console.error("Error fetching books:", e);
        error.value =
            "Unable to load books at this time. Please try again later.";
    } finally {
        isLoading.value = false;
        hasLoaded.value = true;
    }
};

// One watcher over the whole request shape: a navigation that changes two
// keys at once (any sort resets the page) must fetch once, not twice.
watch(
    requestOptions,
    (next, previous) => {
        if (JSON.stringify(next) === JSON.stringify(previous)) return;
        fetchData();
    },
    { immediate: true, deep: true },
);

// Formats gate a control, not the listing — the toolbar renders without them
// and grows the select when they arrive.
onMounted(() => configStore.checkForFormats());
</script>

<template>
    <div>
        <div class="flex flex-wrap items-baseline justify-between gap-x-6">
            <h1 class="mb-2">Library</h1>
            <router-link
                :to="{ name: 'books.new' }"
                class="mb-2 text-sm underline hover:no-underline"
            >
                Add a book &rarr;
            </router-link>
        </div>

        <PageLoadingIndicator v-if="!hasLoaded" />
        <AlertBox v-else-if="error" :message="error" alert-type="danger" />

        <template v-else>
            <p class="text-zinc-600">{{ summary }}</p>

            <LibraryToolbar
                :state="state"
                :formats="formats"
                @navigate="navigate"
            />

            <!-- Later fetches keep the page in place rather than swapping it
                 for a spinner: the list is what the reader is looking at
                 while the search settles. -->
            <div :aria-busy="isLoading">
                <div v-if="!books.length" class="py-6">
                    <p>
                        {{
                            showingDiscarded && !isFiltered
                                ? "Nothing has been discarded."
                                : "No books match."
                        }}
                    </p>
                    <button
                        v-if="isFiltered"
                        type="button"
                        class="text-sm underline hover:no-underline"
                        @click="clearFilters"
                    >
                        Clear search and filters
                    </button>
                </div>

                <section
                    v-for="(section, index) in sections"
                    :key="`${index}-${section.label}`"
                    class="mb-6"
                >
                    <h2
                        class="mb-1 border-b border-zinc-200 pb-1 text-base font-bold text-zinc-500"
                    >
                        {{ section.label }}
                    </h2>
                    <ul class="divide-y divide-zinc-200">
                        <LibraryBookRow
                            v-for="book in section.books"
                            :key="book.book.book_id"
                            :book="book"
                            :discarded="showingDiscarded"
                        />
                    </ul>
                </section>

                <LibraryPagination
                    v-if="pagination"
                    :pagination="pagination"
                    :page-size="state.limit"
                    :link-for="linkFor"
                    @page-size="(limit) => navigate({ limit })"
                />
            </div>
        </template>
    </div>
</template>
