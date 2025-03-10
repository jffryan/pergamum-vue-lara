<template>
    <div v-if="isLoading">
        <PageLoadingIndicator />
    </div>
    <div v-else>
        <div v-if="showErrorMessage">
            <AlertBox :message="error" alert-type="danger" />
        </div>
        <section v-if="bookData">
            <div
                class="p-6 mb-4 bg-zinc-300 border rounded-md border-zinc-400 shadow-md"
            >
                <h1>Edit Book</h1>
                <div class="mb-4">
                    <h2 class="mb-2">Title:</h2>
                    <input type="text" v-model="bookData.book.title" />
                </div>
                <div class="mb-4">
                    <h2 class="flex items-center gap-x-4">
                        Author<span v-if="bookData.authors.length > 1">s</span>:
                    </h2>
                    <div
                        v-for="author in bookData.authors"
                        :key="author.author_id"
                        class="flex gap-x-4 mb-2"
                    >
                        <input type="text" v-model="author.first_name" />
                        <input type="text" v-model="author.last_name" />
                    </div>
                </div>
                <div class="mb-4">
                    <h2>Genres:</h2>
                    <div
                        v-for="genre in bookData.genres"
                        :key="genre.genre_id"
                        class="flex gap-x-4"
                    >
                        <input
                            type="text"
                            v-model="genre.name"
                            class="capitalize mb-2"
                        />
                        <span
                            @click="
                                bookData.genres.splice(
                                    bookData.genres.indexOf(genre),
                                    1,
                                )
                            "
                            class="text-sm hover:underline cursor-pointer"
                            >Remove</span
                        >
                    </div>
                    <span
                        @click="addBlankGenre"
                        class="text-sm hover:underline cursor-pointer"
                        >Add new genre +</span
                    >
                </div>
            </div>
            <div
                class="p-6 mb-4 bg-zinc-300 border rounded-md border-zinc-400 shadow-md"
            >
                <h2>Versions</h2>
                <div class="grid grid-cols-2">
                    <div
                        v-for="version in bookData.versions"
                        :key="version.version_id"
                        class="p-4 mb-4 bg-zinc-100 border rounded-md border-zinc-400 shadow-md"
                    >
                        <p v-if="version.nickname">{{ version.nickname }}</p>
                        <p>
                            <strong>{{ version.format.name }}</strong>
                        </p>
                        <p>{{ version.page_count }}</p>
                        <p v-if="version.format_id === 2">
                            {{ calculateRuntime(version.audio_runtime) }}
                        </p>
                    </div>
                </div>
            </div>
            <div
                class="p-4 mb-4 bg-zinc-300 border rounded-md border-zinc-400 shadow-md"
                v-if="bookData.readInstances.length > 0"
            >
                <h2>Read History</h2>
                <div class="grid grid-cols-2">
                    <div
                        v-for="history in bookData.readInstances"
                        :key="history.read_instances_id"
                        class="p-4 mb-4 bg-zinc-100 border rounded-md border-zinc-400 shadow-md"
                    >
                        <p v-if="bookData.versions.length > 1">
                            Need to sync ability to choose between versions
                        </p>
                        <p>{{ formatDateRead(history.date_read) }}</p>
                        <div class="mb-4">
                            <label
                                for="rating"
                                class="block mb-2 font-bold text-zinc-600 mr-6"
                                >Rating</label
                            >
                            <select
                                v-model="history.rating"
                                class="bg-zinc-100 text-zinc-700 border border-zinc-400 rounded p-2 focus:border-zinc-500 focus:outline-none"
                            >
                                <option value="" class="text-zinc-400" disabled>
                                    Select a rating
                                </option>
                                <option
                                    v-for="(rating, idx) in Array.from(
                                        { length: 9 },
                                        (_, i) => 1 + i * 0.5,
                                    )"
                                    :key="idx"
                                    :value="rating * 2"
                                    class="text-zinc-700"
                                >
                                    {{ rating }}
                                </option>
                            </select>
                        </div>
                    </div>
                </div>
            </div>
            <div>
                <button class="btn btn-primary mr-4" @click="initBookEdits">
                    Save changes
                </button>
                <button
                    class="btn btn-danger mr-4"
                    @click="deleteConfirmation = true"
                >
                    Delete Book
                </button>
                <router-link
                    :to="{ name: 'books.show', slug: currentSlug }"
                    class="btn btn-secondary"
                    >Cancel</router-link
                >
            </div>
            <div v-if="deleteConfirmation" class="pt-6">
                <p class="font-bold">
                    Are you sure you want to delete this book?
                </p>
                <button class="btn btn-danger mr-4" @click="requestDeleteBook">
                    Yes, delete
                </button>
                <button
                    class="btn btn-secondary"
                    @click="deleteConfirmation = false"
                >
                    No, cancel
                </button>
            </div>
        </section>
    </div>
</template>

<script>
import { useBooksStore } from "@/stores";
import {
    calculateRuntime,
    fetchBookData,
    formatDateRead,
} from "@/services/BookServices";
import { updateBook, deleteBook } from "@/api/BookController";

import AlertBox from "@/components/globals/alerts/AlertBox.vue";
import PageLoadingIndicator from "@/components/globals/loading/PageLoadingIndicator.vue";

export default {
    name: "EditBookView",
    setup() {
        const BooksStore = useBooksStore();
        return {
            BooksStore,
            calculateRuntime,
            formatDateRead,
        };
    },
    components: {
        AlertBox,
        PageLoadingIndicator,
    },
    data() {
        return {
            isLoading: true,
            showErrorMessage: false,
            error: "",
            bookData: null,
            deleteConfirmation: false,
        };
    },
    computed: {
        currentBook() {
            return this.BooksStore.allBooks.find(
                (b) => b.book.slug === this.currentSlug,
            );
        },
        currentSlug() {
            return this.$route.params.slug;
        },
    },
    methods: {
        setBookData(bookData) {
            this.bookData = JSON.parse(JSON.stringify(bookData));
        },
        async requestDeleteBook() {
            const { book_id } = this.currentBook.book;
            await deleteBook(book_id);
            this.$router.push({ name: "library.index" });
        },
        addBlankGenre() {
            // If last genre name isn't blank, add a new genre entry with a blank name
            const lastGenre =
                this.bookData.genres[this.bookData.genres.length - 1];
            if (lastGenre.name) {
                this.bookData.genres.push({ name: "" });
            }
        },
        // Come back to this
        async initBookEdits() {
            const { book_id } = this.currentBook.book;
            const bookEdits = {
                book_id,
                formData: this.bookData,
            };

            const res = await this.submitBookEdits(bookEdits);

            if (!(res instanceof Error)) {
                this.BooksStore.updateBook(res.data);
                const { slug } = res.data.book;
                this.$router.push({
                    name: "books.show",
                    params: { slug },
                });
            }
        },
        async submitBookEdits(bookEdits) {
            try {
                const res = await updateBook(bookEdits);
                if (!res.data || res.status !== 200) {
                    throw new Error("Failed to update book");
                }
                return res;
            } catch (error) {
                this.showErrorMessage = true;
                this.error = error;
                return error;
            }
        },
        async findAndSetBookData() {
            // This is basically entirely repeated in BookView but with some
            // minor changes here to account for the mutations inherent to editing
            if (this.currentBook) {
                this.setBookData(this.currentBook);
                this.isLoading = false;
                return;
            }
            const bookData = await fetchBookData(this.currentSlug);
            if (bookData instanceof Error) {
                this.showErrorMessage = true;
                this.error = bookData;
                this.isLoading = false;
                return;
            }
            this.BooksStore.addBook(bookData);
            this.setBookData(bookData);
            this.isLoading = false;
        },
    },
    watch: {
        currentSlug: {
            immediate: true,
            handler: "findAndSetBookData",
        },
    },
};
</script>
