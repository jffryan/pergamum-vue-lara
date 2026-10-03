<template>
    <div>
        <div v-if="isLoading">
            <PageLoadingIndicator />
        </div>
        <div v-else-if="showErrorMessage">
            <AlertBox :message="error" alert-type="danger" />
        </div>
        <div v-else>
            <h1 class="capitalize">{{ genre.name }}</h1>
            <div class="mb-4">
                <div
                    v-for="page in pagination"
                    :key="page.label"
                    class="inline mr-2"
                >
                    <router-link
                        v-if="page.url"
                        :to="page.url"
                        :class="page.active ? 'font-bold underline' : ''"
                    >
                        {{ page.label }}
                    </router-link>
                </div>
                <router-link :to="{ name: 'library.index' }"
                    >Back to Library</router-link
                >
            </div>
            <BookshelfTable :books="books" per-copy />

            <AddBookSearch
                class="mt-6"
                mode="book"
                :is-book-added="isBookAdded"
                @add="addBook"
            />
            <AlertBox
                v-if="addError"
                class="mt-2"
                :message="addError"
                alert-type="danger"
            />
        </div>
    </div>
</template>

<script>
import { bulkTagBooks } from "@/api/BookController";
import { getOneGenre } from "@/api/GenresController";
import { useBooksStore } from "@/stores";

import AddBookSearch from "@/components/books/AddBookSearch.vue";
import AlertBox from "@/components/globals/alerts/AlertBox.vue";
import BookshelfTable from "@/components/books/table/BookshelfTable.vue";
import PageLoadingIndicator from "@/components/globals/loading/PageLoadingIndicator.vue";

export default {
    name: "GenreView",
    components: {
        AddBookSearch,
        AlertBox,
        BookshelfTable,
        PageLoadingIndicator,
    },
    setup() {
        return { BooksStore: useBooksStore() };
    },
    data() {
        return {
            isLoading: true,
            // Search results are a snapshot, so a book tagged from them still
            // reads untagged there; this is what flips its button to Added.
            taggedBookIds: new Set(),
            addError: "",
            genre: null,
            books: [],
            pagination: [],
            showErrorMessage: false,
            error: "",
        };
    },
    computed: {
        currentPage() {
            return this.$route.query.page || 1;
        },
    },
    methods: {
        async fetchAndSetGenreData() {
            const genreId = this.$route.params.id;
            const options = {
                page: this.currentPage,
            };
            try {
                const res = await getOneGenre(genreId, options);
                if (!res.data || res.status !== 200) {
                    throw new Error(
                        "Failed to fetch data: Invalid response from the server",
                    );
                }
                this.setGenre(res.data.genre);
                this.setBooks(res.data.books);
                this.setPagination(
                    this.setPaginationLinks(res.data.pagination),
                );
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
        isBookAdded(result) {
            return (
                this.taggedBookIds.has(result.book.book_id) ||
                result.genres.some((g) => g.genre_id === this.genre.genre_id)
            );
        },
        // By id, not name: the page already holds the row, and a name could
        // re-resolve onto a near-duplicate. See `BulkTagBooksRequest`.
        async addBook(result) {
            const bookId = result.book.book_id;
            this.addError = "";
            try {
                await bulkTagBooks([bookId], {
                    genre_ids: [this.genre.genre_id],
                });
                this.taggedBookIds.add(bookId);
                this.BooksStore.addGenres([bookId], [this.genre]);
                await this.fetchAndSetGenreData();
            } catch (error) {
                console.error("Error tagging book:", error);
                this.addError = `Couldn't add "${result.book.title}" to this genre.`;
            }
        },
        setGenre(genre) {
            this.genre = genre;
        },
        setBooks(books) {
            this.books = books;
        },
        setPagination(pagination) {
            this.pagination = pagination;
        },
        setPaginationLinks(paginationData) {
            const { currentPage, lastPage } = paginationData;
            const paginationLabels = [...Array(lastPage).keys()].map(
                (i) => i + 1,
            );
            return paginationLabels.map((label) => {
                return {
                    label,
                    url: `?page=${label}`,
                    active: label === currentPage,
                };
            });
        },
    },
    watch: {
        "$route.params.id": {
            immediate: true,
            handler() {
                this.taggedBookIds = new Set();
                this.addError = "";
                this.fetchAndSetGenreData();
            },
        },
        currentPage: "fetchAndSetGenreData",
    },
};
</script>
