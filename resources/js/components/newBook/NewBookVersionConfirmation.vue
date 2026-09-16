<script setup>
import { computed } from "vue";
import { useNewBookStore } from "@/stores";

const NewBookStore = useNewBookStore();

const matches = computed(() => NewBookStore.existingMatches);

// Title alone isn't book identity — Plath's Ariel and Rodó's Ariel are two
// books — so each match is shown with its authors and the user picks.
const authorLine = (match) =>
    (match.authors ?? [])
        .map((a) => [a.first_name, a.last_name].filter(Boolean).join(" "))
        .join(", ") || "no authors listed";

const createNewBook = () => {
    NewBookStore.resetToAuthors();
};
</script>
<template>
    <div
        class="p-4 bg-zinc-100 border rounded-md border-zinc-400 mb-8 shadow-md"
    >
        <p class="mb-4">
            {{ matches.length === 1 ? "A book" : "Books" }} with this title
            already exist{{ matches.length === 1 ? "s" : "" }}. Add a new
            version to one of them, or create a different book with the same
            title.
        </p>
        <ul class="mb-4 divide-y divide-zinc-300 border-y border-zinc-300">
            <li
                v-for="match in matches"
                :key="match.book_id"
                class="flex items-center justify-between gap-x-4 py-2"
            >
                <span>
                    <span class="font-bold">{{ match.title }}</span>
                    <span class="text-zinc-600">
                        — {{ authorLine(match) }}</span
                    >
                </span>
                <router-link
                    class="btn btn-primary whitespace-nowrap"
                    :to="{
                        name: 'books.add-version',
                        params: { slug: match.slug },
                    }"
                >
                    Add a version
                </router-link>
            </li>
        </ul>
        <div class="flex gap-x-4">
            <button class="btn btn-primary" @click="createNewBook">
                Create a different book
            </button>
        </div>
    </div>
</template>
