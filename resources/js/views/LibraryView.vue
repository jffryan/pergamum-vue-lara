<template>
    <div>
        <h1>Library</h1>
        <div v-if="isLoading">
            <PageLoadingIndicator />
        </div>
        <div v-else-if="showErrorMessage">
            <AlertBox :message="error" alert-type="danger" />
        </div>
        <div v-else>
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
            </div>
            <BookshelfTable :books="allBooks" class="mb-4" />
            <div class="mb-4 flex items-baseline">
                <input
                    type="text"
                    placeholder="Search books..."
                    class="bg-zinc-50 border border-gray-400 rounded px-2 py-1 mb-2 mr-4"
                    v-model="searchTerm"
                />
                <button
                    class="bg-zinc-50 border border-gray-400 rounded px-2 py-1 btn btn-primary"
                    @click="searchForBookByTitle"
                >
                    Search
                </button>
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
            searchTerm: "",
        };
    },
    computed: {
        allBooks() {
            return this.BooksStore.allBooks;
        },
        currentPage() {
            return this.$route.query.page || 1;
        },
        displayedBooks() {
            // This only works if the book you're looking for is on the page you're actively on.
            // That doesn't really work for users...
            let filteredBooks = [...this.allBooks];

            if (this.searchTerm) {
                filteredBooks = filteredBooks.filter((book) => {
                    return book.title
                        .toLowerCase()
                        .includes(this.searchTerm.toLowerCase());
                });
            }

            return filteredBooks;
        },
    },

    methods: {
        async fetchData() {
            const options = {
                page: this.currentPage,
            };

            try {
                const res = await getAllBooks(options);
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
        async searchForBookByTitle() {
            try {
                const res = await getAllBooks({
                    search: this.searchTerm,
                });
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
        currentPage: {
            immediate: true,
            handler: "fetchData",
        },
    },
};
</script>
