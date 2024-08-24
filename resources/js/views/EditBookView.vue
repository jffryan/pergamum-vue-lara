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
                class="p-4 mb-4 bg-zinc-300 border rounded-md border-zinc-400 shadow-md"
            >
                <h1 class="flex items-center gap-x-4">
                    Edit <input type="text" v-model="bookData.book.title" />
                </h1>
                <h2 class="flex items-center gap-x-4">
                    Author<span v-if="bookData.authors.length > 1">s</span>:
                    <div
                        v-for="author in bookData.authors"
                        :key="author.author_id"
                        class="flex gap-x-4"
                    >
                        <input type="text" v-model="author.first_name" />
                        <input type="text" v-model="author.last_name" />
                    </div>
                </h2>
                <p>
                    <span class="font-bold">Genres: </span>
                    <router-link
                        v-for="(genre, index) in bookData.genres"
                        :key="genre.genre_id"
                        :to="{
                            name: 'genres.show',
                            params: { id: genre.genre_id },
                        }"
                        class="capitalize hover:underline"
                    >
                        {{ genre.name
                        }}<span v-if="index < bookData.genres.length - 1"
                            >,
                        </span>
                    </router-link>
                </p>
            </div>
            <div
                class="p-4 mb-4 bg-zinc-300 border rounded-md border-zinc-400 shadow-md"
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
                <router-link
                    :to="{ name: 'books.show', slug: currentSlug }"
                    class="btn btn-secondary"
                    >Cancel</router-link
                >
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
import { updateBook } from "@/api/BookController";

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
