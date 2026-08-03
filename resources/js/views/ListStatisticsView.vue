<script>
import { getOneList } from "@/api/ListController";
import { useListsStore } from "@/stores";
import listStatistics from "@/services/statistics/surfaces/listStatistics";
import AlertBox from "@/components/globals/alerts/AlertBox.vue";
import BookshelfTable from "@/components/books/table/BookshelfTable.vue";
import PageLoadingIndicator from "@/components/globals/loading/PageLoadingIndicator.vue";
import StatisticsGrid from "@/components/statistics/StatisticsGrid.vue";

/**
 * The numbers come from the statistics endpoint via `StatisticsGrid`; this
 * view only supplies the list's name and the genre drill-down table, which
 * needs the items themselves rather than an aggregate.
 */
export default {
    name: "ListStatisticsView",
    components: {
        AlertBox,
        BookshelfTable,
        PageLoadingIndicator,
        StatisticsGrid,
    },
    setup() {
        return { ListsStore: useListsStore(), surface: listStatistics };
    },
    data() {
        return {
            isLoading: true,
            showErrorMessage: false,
            error: "",
            selectedGenre: null,
        };
    },
    computed: {
        listId() {
            return this.$route.params.id;
        },
        list() {
            return this.ListsStore.currentList;
        },
        items() {
            return this.list?.items ?? [];
        },
        /** Deduplicated by book, keeping the version each book was listed as. */
        uniqueItems() {
            const seen = new Set();

            return this.items.filter((item) => {
                const bookId = item.version.book.book_id;
                if (seen.has(bookId)) return false;
                seen.add(bookId);
                return true;
            });
        },
        booksForGenre() {
            if (!this.selectedGenre) return [];

            return this.uniqueItems
                .filter((item) =>
                    item.version.book.genres.some(
                        (g) => g.name === this.selectedGenre,
                    ),
                )
                .map((item) => ({
                    book: item.version.book,
                    authors: item.version.book.authors,
                    versions: [item.version],
                    genres: item.version.book.genres,
                    readInstances: item.version.book.read_instances,
                }));
        },
    },
    methods: {
        /**
         * Widgets stay generic; the view decides what a selection means. Here
         * a genre becomes a filtered table beneath the grid.
         */
        onWidgetEvent({ widgetId, name, payload }) {
            if (widgetId !== "genres" || name !== "select") return;

            this.selectedGenre =
                this.selectedGenre === payload ? null : payload;
        },
        cached() {
            const current = this.ListsStore.currentList;

            return current && String(current.list_id) === String(this.listId)
                ? current
                : null;
        },
    },
    async mounted() {
        // The list is usually already in the store from the list view itself;
        // fetching again would be the third GET of the same payload.
        if (this.cached()?.items) {
            this.isLoading = false;
            return;
        }

        try {
            const res = await getOneList(this.listId);
            if (!res.data || res.status !== 200) {
                throw new Error("Failed to fetch list");
            }
            this.ListsStore.setCurrentList(res.data);
        } catch (err) {
            console.error("Error fetching list statistics:", err);
            this.showErrorMessage = true;
            this.error =
                "Unable to load statistics for this list. Please try again later.";
        } finally {
            this.isLoading = false;
        }
    },
};
</script>

<template>
    <div>
        <div v-if="isLoading">
            <PageLoadingIndicator />
        </div>
        <div v-else-if="showErrorMessage">
            <AlertBox :message="error" alert-type="danger" />
        </div>
        <div v-else>
            <router-link
                :to="{ name: 'lists.show', params: { id: listId } }"
                class="block mb-4 text-sm text-gray-500 hover:underline"
            >
                ← Back to {{ list.name }}
            </router-link>

            <h1 class="text-2xl font-bold mb-4">{{ list.name }}: Statistics</h1>

            <StatisticsGrid
                :surface="surface"
                :scope-id="listId"
                :widget-props="{ genres: { selected: selectedGenre } }"
                @widget-event="onWidgetEvent"
            />

            <!-- Genre-filtered bookshelf table -->
            <div v-if="selectedGenre" class="mt-6">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-lg font-semibold capitalize">
                        {{ selectedGenre }} books in this list
                    </h2>
                    <button
                        type="button"
                        class="text-sm text-gray-500 hover:underline"
                        @click="selectedGenre = null"
                    >
                        ✕ Close
                    </button>
                </div>
                <BookshelfTable :books="booksForGenre" :bookshelf-title="''" />
            </div>
        </div>
    </div>
</template>
