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
            <BookshelfTable :books="books" />
        </div>
    </div>
</template>

<script>
import { getOneGenre } from "@/api/GenresController";

import AlertBox from "@/components/globals/alerts/AlertBox.vue";
import BookshelfTable from "@/components/books/table/BookshelfTable.vue";
import PageLoadingIndicator from "@/components/globals/loading/PageLoadingIndicator.vue";

export default {
    name: "GenreView",
    components: {
        AlertBox,
        BookshelfTable,
        PageLoadingIndicator,
    },
    data() {
        return {
            isLoading: true,
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
            try {
                const res = await getOneGenre(genreId);
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
            handler: "fetchAndSetGenreData",
        },
        currentPage: "fetchAndSetGenreData",
    },
};
</script>
