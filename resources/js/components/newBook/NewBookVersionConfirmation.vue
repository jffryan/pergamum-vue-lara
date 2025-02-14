<script setup>
import { computed } from "vue";
import { useNewBookStore } from "@/stores";

const NewBookStore = useNewBookStore();

const bookSlug = computed(() => NewBookStore.currentBookData.book.slug);

const createNewBook = () => {
    NewBookStore.resetToAuthors();
};
</script>
<template>
    <div
        class="p-4 bg-zinc-100 border rounded-md border-zinc-400 mb-8 shadow-md"
    >
        <p>
            A book with this title has been found. Do you want to create a new
            book with the same title, or a new version of the existing book?
        </p>
        <div class="flex gap-x-4">
            <button class="btn btn-primary" @click="createNewBook">
                Create New Book
            </button>
            <router-link
                class="btn btn-primary"
                :to="{ name: 'books.add-version', params: { slug: bookSlug } }"
            >
                Create New Version
            </router-link>
        </div>
    </div>
</template>
