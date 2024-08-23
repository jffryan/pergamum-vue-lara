<template>
    <div v-if="isLoading">
        <PageLoadingIndicator />
    </div>
    <div v-else-if="showErrorMessage">
        <AlertBox :message="error" alert-type="danger" />
    </div>
    <div v-else>
        <div class="grid grid-cols-2">
            <div class="mb-12">
                <h1>{{ currentBook.book.title }}</h1>
                <h2>
                    <span
                        v-for="author in currentAuthors"
                        :key="author.author_id"
                        ><router-link
                            :to="{
                                name: 'authors.show',
                                params: { slug: author.slug },
                            }"
                            class="hover:underline"
                            >{{ author.name }}</router-link
                        ><span
                            v-if="
                                author !==
                                currentAuthors[currentAuthors.length - 1]
                            "
                            >,
                        </span>
                    </span>
                </h2>
                <p>
                    <span class="font-bold">Genres: </span>
                    <router-link
                        v-for="(genre, index) in currentGenres"
                        :key="genre.genre_id"
                        :to="{
                            name: 'genres.show',
                            params: { id: genre.genre_id },
                        }"
                        class="capitalize hover:underline"
                    >
                        {{ genre.name
                        }}<span v-if="index < currentGenres.length - 1"
                            >,
                        </span>
                    </router-link>
                </p>
            </div>
            <div class="pl-12">
                <div class="mb-4">
                    <router-link
                        class="btn btn-primary mr-4"
                        :to="{
                            name: 'books.add-read-history',
                            params: { slug: currentBook.slug },
                        }"
                        >Add read history</router-link
                    >
                    <router-link
                        class="btn btn-secondary mr-4"
                        :to="{
                            name: 'books.edit',
                            params: { slug: currentBook.slug },
                        }"
                        >Edit book</router-link
                    >
                </div>
                <div class="mb-8">
                    <router-link
                        :to="{ name: 'library.index' }"
                        class="block mb-4"
                        >Back to Library</router-link
                    >
                </div>

                <div class="mb-8">
                    <h3>Versions</h3>
                    <VersionTable :versions="currentBook.versions" />
                </div>
                <div v-if="bookHasBeenCompleted">
                    <div class="p-4 rounded-t-md bg-slate-900 text-slate-200">
                        <h3 class="mb-0">Read History</h3>
                    </div>

                    <div class="p-4 rounded-b-md bg-slate-200">
                        <div
                            v-for="readInstance in readHistory"
                            :key="readInstance.date_read"
                        >
                            <p>
                                <span class="text-zinc-600">Date read: </span
                                >{{ readInstance.date_read }}
                            </p>
                            <p>
                                <span class="text-zinc-600">Version: </span
                                >{{ readInstance.version }}
                            </p>
                        </div>
                        <p><span class="text-zinc-600">Rating: </span>ERR</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
import { useBooksStore } from "@/stores";

import { fetchBookData } from "@/services/BookServices";

import AlertBox from "@/components/globals/alerts/AlertBox.vue";
import PageLoadingIndicator from "@/components/globals/loading/PageLoadingIndicator.vue";
import VersionTable from "@/components/books/table/VersionTable.vue";

export default {
    name: "BookView",
    setup() {
        const BooksStore = useBooksStore();

        return {
            BooksStore,
        };
    },
    components: {
        AlertBox,
        PageLoadingIndicator,
        VersionTable,
    },
    data() {
        return {
            isLoading: true,
            showErrorMessage: false,
            error: "",
        };
    },
    computed: {
        currentSlug() {
            return this.$route.params.slug;
        },
        currentBook() {
            return this.BooksStore.allBooks.find(
                (b) => b.book.slug === this.$route.params.slug,
            );
        },
        bookHasBeenCompleted() {
            if (this.currentBook && this.currentBook.readInstances.length > 0) {
                return true;
            }
            return false;
        },
        currentAuthors() {
            if (this.currentBook) {
                return this.currentBook.authors.map((author) => {
                    const firstName = author.first_name || "";
                    const lastName = author.last_name || "";
                    const slug = author.slug || "";
                    return { name: `${firstName} ${lastName}`.trim(), slug };
                });
            }
            return [];
        },
        currentGenres() {
            if (this.currentBook) {
                return this.currentBook.genres;
            }
            return [];
        },
        readHistory() {
            if (this.currentBook.readInstances.length === 0) return "";

            // Loop through all read instances and return an array in MM/DD/YYYY format
            const { readInstances } = this.currentBook;
            const formattedReadInstances = [];

            for (let i = 0; i < readInstances.length; i += 1) {
                // Grab the instance
                const readInstance = readInstances[i];
                // Format date
                const unformattedDate = readInstance.date_read;
                const [year, month, day] = unformattedDate.split("-");
                const formattedDateRead = `${month}/${day}/${year}`;
                // Find the version
                const readInstanceVersion = this.findReadInstanceVersion(
                    readInstance.version_id,
                );
                // Grab the version's format name
                const versionFormatName = readInstanceVersion.format.name;
                // Format the read instance
                const formattedReadInstance = {
                    date_read: formattedDateRead,
                    version: versionFormatName,
                };
                formattedReadInstances.push(formattedReadInstance);
            }
            // MM/DD/YYYY

            return formattedReadInstances;
        },
    },
    methods: {
        findReadInstanceVersion(version_id) {
            return this.currentBook.versions.find(
                (version) => version.version_id === version_id,
            );
        },
        async setBookData() {
            // This repeats the isLoading logic a lot. LibraryView is cleaner in that regard
            if (this.currentBook) {
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
            this.isLoading = false;
        },
    },
    watch: {
        currentSlug: {
            immediate: true,
            handler: "setBookData",
        },
    },
};
</script>
