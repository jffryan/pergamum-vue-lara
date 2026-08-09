<template>
    <div>
        <h1>{{ showingDiscarded ? "Discarded" : "Library" }}</h1>
        <div class="mb-4">
            <router-link
                :to="{ name: 'library.index' }"
                :class="showingDiscarded ? '' : 'font-bold underline'"
                class="mr-4"
                >On the shelf</router-link
            >
            <router-link
                :to="{ name: 'library.index', query: { discarded: 'only' } }"
                :class="showingDiscarded ? 'font-bold underline' : ''"
                >Discarded</router-link
            >
        </div>
        <div v-if="isLoading">
            <PageLoadingIndicator />
        </div>
        <div v-else-if="showErrorMessage">
            <AlertBox :message="error" alert-type="danger" />
        </div>
        <div v-else>
            <div class="mb-4">
                <form
                    class="mb-4 flex items-baseline"
                    @submit.prevent="submitSearch"
                >
                    <input
                        type="text"
                        placeholder="Search books..."
                        class="bg-zinc-50 border border-gray-400 rounded px-2 py-1 mb-2 mr-4"
                        v-model="searchTerm"
                    />
                    <button
                        type="submit"
                        class="bg-zinc-50 border border-gray-400 rounded px-2 py-1 btn btn-primary"
                    >
                        Search
                    </button>
                    <button
                        v-if="activeSearch"
                        type="button"
                        class="ml-2 px-2 py-1 underline"
                        @click="clearSearch"
                    >
                        Clear
                    </button>
                </form>
            </div>
            <BookshelfTable
                :books="allBooks"
                class="mb-4"
                sortable
                :sort-key="sortKey"
                :sort-direction="sortDirection"
                @sort="applySort"
            />
            <div
                v-for="page in pagination"
                :key="page.label"
                class="inline mr-2"
            >
                <router-link
                    :to="page.to"
                    :class="page.active ? 'font-bold underline' : ''"
                >
                    {{ page.label }}
                </router-link>
            </div>
        </div>
    </div>
</template>

<script>
import { getAllBooks } from "@/api/BookController";

import { useBooksStore } from "@/stores";

import AlertBox from "@/components/globals/alerts/AlertBox.vue";
import BookshelfTable from "@/components/books/table/BookshelfTable.vue";
import PageLoadingIndicator from "@/components/globals/loading/PageLoadingIndicator.vue";

// Mirrors BookController::SORTABLE. An unrecognized key falls back server-side
// too, so a stale bookmark renders the library rather than erroring — this is
// only here so the header arrow doesn't point at a column that isn't sorted.
const SORT_KEYS = ["title", "author", "format", "pages", "date_read", "rating"];
const DEFAULT_SORT = "author";

export default {
    name: "LibraryView",
    components: {
        AlertBox,
        BookshelfTable,
        PageLoadingIndicator,
    },
    setup() {
        const BooksStore = useBooksStore();

        return {
            BooksStore,
        };
    },
    data() {
        return {
            isLoading: true,
            showErrorMessage: false,
            error: "",
            pagination: [],
            // Local only while the user is typing; the committed term lives in
            // the URL so sorting and paginating keep it.
            searchTerm: this.$route.query.search || "",
        };
    },
    computed: {
        allBooks() {
            return this.BooksStore.allBooks;
        },
        currentPage() {
            return Number(this.$route.query.page) || 1;
        },
        // Absent means "on the shelf" — the backend defaults to excluding
        // books whose every version has been discarded.
        discardedMode() {
            return this.$route.query.discarded || "";
        },
        showingDiscarded() {
            return this.discardedMode === "only";
        },
        activeSearch() {
            return this.$route.query.search || "";
        },
        sortKey() {
            const key = this.$route.query.sort;

            return SORT_KEYS.includes(key) ? key : DEFAULT_SORT;
        },
        sortDirection() {
            return this.$route.query.direction === "desc" ? "desc" : "asc";
        },
        // The listing is paginated server-side, so every control that changes
        // what the query returns has to go through the URL — sorting a page of
        // twenty would rank a slice of the library rather than the library.
        requestOptions() {
            const options = {
                page: this.currentPage,
                sort: this.sortKey,
                direction: this.sortDirection,
            };

            if (this.activeSearch) options.search = this.activeSearch;
            if (this.discardedMode) options.discarded = this.discardedMode;

            return options;
        },
    },

    methods: {
        async fetchData() {
            this.isLoading = true;
            this.showErrorMessage = false;

            try {
                const res = await getAllBooks(this.requestOptions);
                if (!res.data || res.status !== 200) {
                    throw new Error(
                        "Failed to fetch data: Invalid response from the server",
                    );
                }
                this.BooksStore.setAllBooks(res.data.books);
                this.pagination = this.setPaginationLinks(res.data.pagination);
            } catch (error) {
                // Log the error for debugging purposes
                console.error("Error fetching books:", error);

                // Provide user feedback
                this.showErrorMessage = true;
                this.error =
                    "Unable to load books at this time. Please try again later.";
            } finally {
                this.isLoading = false;
            }
        },
        // Every navigation below drops back to page 1: the row that was on
        // page 4 under one sort is somewhere else entirely under the next.
        navigate(query) {
            this.$router.push({
                name: "library.index",
                query: this.buildQuery({ ...query, page: undefined }),
            });
        },
        buildQuery(overrides = {}) {
            const merged = {
                sort: this.sortKey,
                direction: this.sortDirection,
                search: this.activeSearch,
                discarded: this.discardedMode,
                page: this.currentPage,
                ...overrides,
            };

            // Keep defaults out of the URL so the common case stays a clean
            // /library and the back button doesn't collect no-op entries.
            if (merged.sort === DEFAULT_SORT) delete merged.sort;
            if (merged.direction === "asc") delete merged.direction;
            if (merged.page === 1) delete merged.page;

            return Object.fromEntries(
                Object.entries(merged).filter(
                    ([, value]) => value !== "" && value !== undefined,
                ),
            );
        },
        applySort({ key, direction }) {
            this.navigate({ sort: key, direction });
        },
        submitSearch() {
            this.navigate({ search: this.searchTerm });
        },
        clearSearch() {
            this.searchTerm = "";
            this.navigate({ search: "" });
        },
        setPaginationLinks(paginationData) {
            const { currentPage, lastPage } = paginationData;
            const paginationLabels = [...Array(lastPage).keys()].map(
                (i) => i + 1,
            );

            return paginationLabels.map((label) => ({
                label,
                // Paging keeps the sort, search and shelf context it was
                // reached under.
                to: {
                    name: "library.index",
                    query: this.buildQuery({ page: label }),
                },
                active: label === currentPage,
            }));
        },
    },

    watch: {
        // One watcher over the whole request shape: page, sort, direction,
        // search and shelf all change the same query, and watching them
        // separately fired duplicate fetches whenever a navigation changed two
        // at once (sorting always resets the page).
        requestOptions: {
            immediate: true,
            deep: true,
            handler(next, previous) {
                if (JSON.stringify(next) === JSON.stringify(previous)) return;
                this.fetchData();
            },
        },
        activeSearch(term) {
            // Keep the input in step when the term changes from outside it —
            // a back navigation, or the clear button.
            this.searchTerm = term;
        },
    },
};
</script>
