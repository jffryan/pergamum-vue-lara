<template>
    <div>
        <div
            class="lg:w-2/3 px-6 py-8 bg-zinc-300 border rounded-md border-zinc-400 mb-4 shadow-lg"
        >
            <div>
                <span
                    @click="hasHistory() ? $router.go(-1) : $router.push('/')"
                    class="block mb-2 cursor-pointer text-zinc-600 hover:text-zinc-700 hover:underline"
                >
                    Go Back</span
                >
                <h1>Add Version</h1>
            </div>
            <div v-if="currentBook">
                <div
                    class="p-4 mb-4 bg-white border rounded-md border-zinc-400 shadow-md"
                >
                    <h2>Book Information</h2>
                    <p>Title: {{ currentBook.book.title }}</p>
                    <p>
                        Author<span v-if="currentAuthors.length > 1">s</span>:
                        <span
                            v-for="author in currentAuthors"
                            :key="author.author_id"
                            >{{ author }}</span
                        >
                    </p>
                </div>
                <!-- End book information -->
                <div class="grid grid-cols-2 gap-x-4">
                    <div
                        v-for="version in currentVersions"
                        :key="version.version_id"
                        class="p-4 mb-4 bg-white border rounded-md border-zinc-400 shadow-md"
                    >
                        <p v-if="version.nickname">{{ version.nickname }}</p>
                        <p class="capitalize">
                            <strong>{{ version.format.name }}</strong>
                        </p>
                        <p>{{ version.page_count }}</p>
                        <p v-if="version.format_id === 2">
                            {{ version.audio_runtime }}
                        </p>
                    </div>
                </div>
                <!-- End versions -->
                <NewVersionsInput
                    :existing-book="true"
                    :book-id="currentBook.book.book_id"
                />
            </div>
        </div>
    </div>
</template>

<script>
import { useBooksStore, useNewBookStore } from "@/stores";

import { getOneBookFromSlug } from "@/api/BookController";

import NewVersionsInput from "@/components/newBook/NewVersionsInput.vue";

export default {
    name: "AddVersionView",
    components: {
        NewVersionsInput,
    },
    setup() {
        const BooksStore = useBooksStore();
        const NewBookStore = useNewBookStore();

        return {
            BooksStore,
            NewBookStore,
        };
    },
    computed: {
        currentBook() {
            return this.BooksStore.allBooks.find(
                (b) => b.book.slug === this.$route.params.slug,
            );
        },
        currentAuthors() {
            return this.currentBook.authors.map((author) => {
                const firstName = author.first_name || "";
                const lastName = author.last_name || "";
                return `${firstName} ${lastName}`.trim();
            });
        },
        currentVersions() {
            return this.currentBook.versions;
        },
    },
    methods: {
        hasHistory() {
            return window.history.length > 2;
        },
    },
    async mounted() {
        if (!this.currentBook) {
            try {
                const book = await getOneBookFromSlug(this.$route.params.slug);
                this.BooksStore.addBook(book.data);
            } catch (error) {
                console.log("ERROR: ", error);
            }
        }
        this.NewBookStore.setBookFromExisting(this.currentBook);
    },
};
</script>
