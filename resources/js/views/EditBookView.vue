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
                        v-for="(author, idx) in bookData.authors"
                        :key="author.author_id ? author.author_id : idx"
                        class="mb-2"
                    >
                        <div class="flex gap-x-4 items-center">
                            <input type="text" v-model="author.first_name" placeholder="First" />
                            <input
                                type="text"
                                v-model="author.last_name"
                                placeholder="Last"
                                @input="clearAuthorValidation(idx)"
                            />
                            <span
                                v-if="bookData.authors.length > 1"
                                @click="removeAuthor(idx)"
                                class="text-sm hover:underline cursor-pointer"
                                >Remove</span
                            >
                        </div>
                        <p v-if="!isValidAuthors[idx]" class="text-sm text-red-500">
                            Last name is required.
                        </p>
                    </div>
                    <span
                        @click="addBlankAuthor"
                        :class="[
                            'text-sm cursor-pointer',
                            canAddMoreAuthors
                                ? 'hover:underline'
                                : 'text-zinc-400 cursor-not-allowed',
                        ]"
                        >Add new author +</span
                    >
                </div>
                <div class="mb-4">
                    <h2>Genres:</h2>
                    <GenreTagInput v-model="bookData.genres" />
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
                        <div class="mb-4">
                            <label class="block mb-2 font-bold text-zinc-600">Format</label>
                            <select v-model="version.format_id" class="bg-zinc-100 text-zinc-700 border border-zinc-400 rounded p-2 focus:border-zinc-500 focus:outline-none">
                                <option value="" disabled>Select a format</option>
                                <option
                                    v-for="format in formats"
                                    :key="format.format_id"
                                    :value="format.format_id"
                                >{{ format.name }}</option>
                            </select>
                        </div>
                        <div class="mb-4">
                            <label class="block mb-2 font-bold text-zinc-600">Page Count</label>
                            <input
                                type="text"
                                v-model="version.page_count"
                                @input="version.page_count = $event.target.value.replace(/[^0-9]/g, '')"
                            />
                        </div>
                        <div v-if="isAudiobook(version)" class="mb-4">
                            <label class="block mb-2 font-bold text-zinc-600">Audio Runtime (minutes)</label>
                            <input
                                type="text"
                                v-model="version.audio_runtime"
                                @input="version.audio_runtime = $event.target.value.replace(/[^0-9]/g, '')"
                            />
                        </div>
                        <div class="mb-4">
                            <label class="block mb-2 font-bold text-zinc-600">Nickname</label>
                            <input type="text" v-model="version.nickname" placeholder="e.g. Hardcover" />
                        </div>
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
                    :to="{ name: 'books.show', params: { slug: currentSlug } }"
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
import { useBooksStore, useConfigStore } from "@/stores";
import {
    calculateRuntime,
    fetchBookData,
    formatDateRead,
} from "@/services/BookServices";
import { updateBook, deleteBook } from "@/api/BookController";
import { validateString } from "@/utils/validators";

import AlertBox from "@/components/globals/alerts/AlertBox.vue";
import PageLoadingIndicator from "@/components/globals/loading/PageLoadingIndicator.vue";
import GenreTagInput from "@/components/newBook/GenreTagInput.vue";

export default {
    name: "EditBookView",
    setup() {
        const BooksStore = useBooksStore();
        const ConfigStore = useConfigStore();
        return {
            BooksStore,
            ConfigStore,
            calculateRuntime,
            formatDateRead,
        };
    },
    components: {
        AlertBox,
        PageLoadingIndicator,
        GenreTagInput,
    },
    data() {
        return {
            isLoading: true,
            showErrorMessage: false,
            error: "",
            bookData: null,
            deleteConfirmation: false,
            isValidAuthors: [],
        };
    },
    async created() {
        await this.ConfigStore.checkForFormats();
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
        canAddMoreAuthors() {
            if (!this.bookData) return false;
            const lastAuthor = this.bookData.authors[this.bookData.authors.length - 1];
            return lastAuthor.first_name !== "" || lastAuthor.last_name !== "";
        },
        formats() {
            return this.ConfigStore.books.formats;
        },
    },
    methods: {
        setBookData(bookData) {
            this.bookData = JSON.parse(JSON.stringify(bookData));
            this.isValidAuthors = this.bookData.authors.map(() => true);
        },
        async requestDeleteBook() {
            const { book_id } = this.bookData.book;
            await deleteBook(book_id);
            this.$router.push({ name: "library.index" });
        },
        isAudiobook(version) {
            return this.formats.find((f) => f.format_id === version.format_id)?.name === "Audiobook";
        },
        addBlankAuthor() {
            if (this.canAddMoreAuthors) {
                this.bookData.authors.push({ first_name: "", last_name: "" });
                this.isValidAuthors.push(true);
            }
        },
        removeAuthor(idx) {
            if (this.bookData.authors.length > 1) {
                this.bookData.authors.splice(idx, 1);
                this.isValidAuthors.splice(idx, 1);
            }
        },
        clearAuthorValidation(idx) {
            this.isValidAuthors[idx] = true;
        },
        validateAuthors() {
            this.isValidAuthors = this.bookData.authors.map((author) =>
                validateString(author.last_name),
            );
            return this.isValidAuthors.every((v) => v);
        },
        // Come back to this
        async initBookEdits() {
            if (!this.validateAuthors()) {
                return;
            }
            const book_id = this.bookData.book.book_id;

            const transformedVersions = this.bookData.versions.map((version) => ({
                version_id: version.version_id,
                format: version.format_id,
                page_count: version.page_count,
                audio_runtime: this.isAudiobook(version) ? version.audio_runtime : null,
                nickname: version.nickname,
            }));

            const bookEdits = {
                book_id,
                formData: {
                    ...this.bookData,
                    versions: transformedVersions,
                },
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
                this.error = error.message;
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
