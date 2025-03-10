<template>
    <div>
        <div
            class="p-4 bg-zinc-100 border rounded-md border-zinc-400 mb-8 shadow-md"
        >
            <div v-if="title" class="mb-2">
                <p class="font-bold mb-0">
                    Title: <span class="font-normal">{{ title }}</span>
                </p>
            </div>
            <div v-if="authors && authors.length" class="mb-2">
                <p class="font-bold mb-0">
                    Authors:
                    <span
                        v-for="(author, index) in authors"
                        :key="author.author_id"
                        class="font-normal"
                    >
                        {{ author.first_name }} {{ author.last_name
                        }}<span v-if="index < authors.length - 1">, </span>
                    </span>
                </p>
            </div>
            <div v-if="genres && genres.length" class="mb-2">
                <p class="font-bold mb-0">
                    Genres:
                    <span
                        v-for="(genre, index) in genres"
                        :key="genre.genre_id"
                        class="font-normal capitalize"
                    >
                        {{ genre.name
                        }}<span v-if="index < genres.length - 1">, </span>
                    </span>
                </p>
            </div>
        </div>
        <div v-if="versions && versions.length" class="mb-8">
            <div class="grid grid-cols-2 gap-x-4 gap-y-4">
                <div
                    v-for="version in versions"
                    :key="version.version_id"
                    class="p-4 bg-zinc-100 border rounded-md border-zinc-400 mb-2 shadow-md"
                >
                    <div class="font-bold capitalize">
                        {{ version.format.name }}
                    </div>
                    <!-- Page Count conditional on format -->
                    <div v-if="!version.audio_runtime" class="font-normal">
                        {{ version.page_count }} pages
                    </div>
                    <!-- Audio Runtime conditional on format -->
                    <div v-if="version.audio_runtime" class="font-normal">
                        {{ Math.floor(version.audio_runtime / 60) }} hours
                        {{ version.audio_runtime % 60 }} minutes
                    </div>
                    <div
                        v-if="
                            version.read_instances &&
                            version.read_instances.length
                        "
                    >
                        Completed:
                        {{ formatDate(version.read_instances[0].date_read) }}
                    </div>
                </div>
                <div
                    v-if="backlogItem"
                    class="p-4 bg-zinc-100 border rounded-md border-zinc-400 mb-8 shadow-md"
                >
                    <p class="mb-0">
                        This book will be added to your current backlog.
                    </p>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
import { useNewBookStore } from "@/stores";

export default {
    name: "NewBookProgressForm",
    setup() {
        const NewBookStore = useNewBookStore();

        return {
            NewBookStore,
        };
    },
    computed: {
        title() {
            return this.NewBookStore.currentBookData.book.title;
        },
        authors() {
            return this.NewBookStore.currentBookData.authors;
        },
        genres() {
            return this.NewBookStore.currentBookData.genres;
        },
        versions() {
            return this.NewBookStore.currentBookData.versions;
        },
        backlogItem() {
            return this.NewBookStore.currentBookData.addToBacklog;
        },
    },
    methods: {
        formatDate(date) {
            return new Date(date).toString().split(" ").slice(1, 4).join(" ");
        },
    },
};
</script>
