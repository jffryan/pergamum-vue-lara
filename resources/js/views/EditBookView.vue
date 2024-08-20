<template>
    <div v-if="!currentBook">
        <div class="bg-slate-400 p-4 w-1/2 mb-4"></div>
        <div class="bg-slate-400 p-4 w-1/2 mb-4"></div>
    </div>
    <div v-else>
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
                    }}<span v-if="index < bookData.genres.length - 1">, </span>
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
                        {{ version.audio_runtime }}
                    </p>
                </div>
            </div>
        </div>
        <div
            class="p-4 mb-4 bg-zinc-300 border rounded-md border-zinc-400 shadow-md"
        >
            <h2>Read History</h2>
            <div class="grid grid-cols-2">
                <div
                    v-for="history in bookData.read_instances"
                    :key="history.read_instances_id"
                    class="p-4 mb-4 bg-zinc-100 border rounded-md border-zinc-400 shadow-md"
                >
                    <p>{{ history.date_read }}</p>
                    <p>Need to get version associated via version_id</p>
                </div>
            </div>
        </div>
        <div>
            <button class="btn btn-primary mr-4" @click="initBookEdit">
                Save changes
            </button>
            <router-link
                :to="{ name: 'books.show', slug: currentSlug }"
                class="btn btn-secondary"
                >Cancel</router-link
            >
        </div>
    </div>
</template>

<script>
import { useBooksStore } from "@/stores";

// import checkForChanges from "@/utils/checkForChanges";

import { getOneBookFromSlug, patchBook } from "@/api/BookController";

export default {
    name: "EditBookView",

    setup() {
        const BooksStore = useBooksStore();

        return {
            BooksStore,
        };
    },
    data() {
        return {
            currentSlug: this.$route.params.slug,
            bookData: null,
        };
    },
    computed: {
        currentBook() {
            return this.BooksStore.allBooks.find(
                (b) => b.book.slug === this.$route.params.slug,
            );
        },
    },
    methods: {
        setBookData(bookData) {
            this.bookData = JSON.parse(JSON.stringify(bookData));
        },
        async initBookEdit() {
            const { book_id } = this.currentBook;
            const request = {
                book_id,
                book: this.bookData,
            };
            await patchBook(request);
        },
    },
    async created() {
        if (!this.currentBook) {
            try {
                const book = await getOneBookFromSlug(this.currentSlug);
                if (!book.data) {
                    this.$router.push({ name: "NotFound" });
                }
                this.BooksStore.addBook(book.data);
            } catch (error) {
                console.log("404!!");
                console.log(error);
                this.$router.push({ name: "NotFound" });
            }
        }
        this.setBookData(this.currentBook);
    },
};
</script>
