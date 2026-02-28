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
                :to="{ name: 'lists.show', params: { id: $route.params.id } }"
                class="block mb-4 text-sm text-gray-500 hover:underline"
            >
                ← Back to {{ list.name }}
            </router-link>

            <h1 class="text-2xl font-bold mb-4">{{ list.name }}: Statistics</h1>

            <div v-if="totalItems === 0" class="text-gray-500">
                This list has no items yet.
            </div>
            <template v-else>
            <div class="bg-zinc-800 text-white rounded">
                <div class="grid grid-cols-12 gap-2 sm:gap-4 p-4">
                    <!-- Total items -->
                    <div class="p-3 sm:p-4 bg-slate-600 col-span-6 sm:col-span-3">
                        <span class="block text-3xl sm:text-5xl font-bold mb-2 sm:mb-4">{{
                            totalItems
                        }}</span>
                        <p class="text-sm sm:text-lg">Books on List</p>
                    </div>

                    <!-- Completed count -->
                    <div class="p-3 sm:p-4 bg-slate-600 col-span-6 sm:col-span-3">
                        <span class="block text-3xl sm:text-5xl font-bold mb-2 sm:mb-4">{{
                            completedCount
                        }}</span>
                        <p class="text-sm sm:text-lg">Books Completed</p>
                    </div>

                    <!-- % Completed -->
                    <div class="p-3 sm:p-4 bg-zinc-700 col-span-6 sm:col-span-3">
                        <span class="block text-3xl sm:text-5xl font-bold mb-2 sm:mb-4">{{
                            completedPercent
                        }}%</span>
                        <p class="text-sm sm:text-lg">of List Completed</p>
                    </div>

                    <!-- Total pages -->
                    <div class="p-3 sm:p-4 bg-zinc-700 col-span-6 sm:col-span-3">
                        <span class="block text-3xl sm:text-5xl font-bold mb-2 sm:mb-4">{{
                            formattedTotalPages
                        }}</span>
                        <p class="text-sm sm:text-lg">Total Pages</p>
                    </div>

                    <!-- Genre breakdown -->
                    <div
                        v-if="genreCounts.length"
                        class="p-4 bg-zinc-700 col-span-12"
                        :class="averageRating !== null ? 'sm:col-span-8' : ''"
                    >
                        <h3 class="font-semibold mb-3">Genres</h3>
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-1">
                            <button
                                v-for="genre in genreCounts"
                                :key="genre.name"
                                class="text-sm capitalize text-left hover:underline"
                                :class="selectedGenre === genre.name ? 'font-semibold' : ''"
                                @click="toggleGenre(genre.name)"
                            >
                                {{ genre.name }}
                                <span class="text-zinc-400">({{ genre.count }})</span>
                            </button>
                        </div>
                    </div>

                    <!-- Average rating -->
                    <div
                        v-if="averageRating !== null"
                        class="p-3 sm:p-4 bg-zinc-700 col-span-12 sm:col-span-4"
                    >
                        <span class="block text-3xl sm:text-5xl font-bold mb-2 sm:mb-4">{{
                            averageRating
                        }}</span>
                        <p class="text-sm sm:text-lg">Avg. Rating (out of 5)</p>
                    </div>
                </div>
            </div>

            <!-- Genre-filtered bookshelf table -->
            <div v-if="selectedGenre" class="mt-6">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-lg font-semibold capitalize">
                        {{ selectedGenre }} books in this list
                    </h2>
                    <button
                        @click="selectedGenre = null"
                        class="text-sm text-gray-500 hover:underline"
                    >
                        ✕ Close
                    </button>
                </div>
                <BookshelfTable :books="booksForGenre" :bookshelf-title="''" />
            </div>
            </template>
        </div>
    </div>
</template>

<script>
import { getOneList } from "@/api/ListController";
import AlertBox from "@/components/globals/alerts/AlertBox.vue";
import BookshelfTable from "@/components/books/table/BookshelfTable.vue";
import PageLoadingIndicator from "@/components/globals/loading/PageLoadingIndicator.vue";

export default {
    name: "ListStatisticsView",
    components: {
        AlertBox,
        BookshelfTable,
        PageLoadingIndicator,
    },
    data() {
        return {
            isLoading: true,
            showErrorMessage: false,
            error: "",
            list: null,
            selectedGenre: null,
        };
    },
    computed: {
        items() {
            return this.list?.items ?? [];
        },
        // Deduplicated by book — preserves the full item (including version) for each unique book
        uniqueItems() {
            const seen = new Set();
            return this.items.filter((item) => {
                const bookId = item.version.book.book_id;
                if (seen.has(bookId)) return false;
                seen.add(bookId);
                return true;
            });
        },
        uniqueBooks() {
            return this.uniqueItems.map((item) => item.version.book);
        },
        totalItems() {
            return this.uniqueBooks.length;
        },
        completedCount() {
            return this.uniqueBooks.filter((book) => book.read_instances.length > 0).length;
        },
        completedPercent() {
            if (!this.totalItems) return 0;
            return Math.round((this.completedCount / this.totalItems) * 100);
        },
        totalPages() {
            return this.items.reduce((sum, item) => sum + (item.version.page_count || 0), 0);
        },
        formattedTotalPages() {
            return this.totalPages.toLocaleString();
        },
        genreCounts() {
            const counts = {};
            this.uniqueBooks.forEach((book) => {
                book.genres.forEach((genre) => {
                    counts[genre.name] = (counts[genre.name] || 0) + 1;
                });
            });
            return Object.entries(counts)
                .sort(([, a], [, b]) => b - a)
                .map(([name, count]) => ({ name, count }));
        },
        averageRating() {
            // Ratings are stored doubled (1–5 scale → 2–10 in DB); divide by 2 to display
            const ratings = this.uniqueBooks
                .flatMap((book) => book.read_instances)
                .map((ri) => ri.rating)
                .filter((r) => r !== null && r > 0);
            if (!ratings.length) return null;
            const avg = ratings.reduce((sum, r) => sum + r, 0) / ratings.length;
            return (avg / 2).toFixed(1);
        },
        // List items filtered to selectedGenre, shaped for BookshelfTable/BookTableRow
        booksForGenre() {
            if (!this.selectedGenre) return [];
            return this.uniqueItems
                .filter((item) =>
                    item.version.book.genres.some((g) => g.name === this.selectedGenre),
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
        toggleGenre(genreName) {
            this.selectedGenre = this.selectedGenre === genreName ? null : genreName;
        },
    },
    async mounted() {
        const listId = this.$route.params.id;
        try {
            const res = await getOneList(listId);
            if (!res.data || res.status !== 200) {
                throw new Error("Failed to fetch list");
            }
            this.list = res.data;
        } catch (err) {
            console.error("Error fetching list statistics:", err);
            this.showErrorMessage = true;
            this.error = "Unable to load statistics for this list. Please try again later.";
        } finally {
            this.isLoading = false;
        }
    },
};
</script>
