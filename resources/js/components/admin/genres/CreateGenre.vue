<script setup>
import { ref } from "vue";
import { useGenreStore } from "@/stores";

const genreStore = useGenreStore();

const name = ref("");
const error = ref(null);
const conflict = ref(null);
const success = ref(false);
const busy = ref(false);

async function submit() {
    error.value = null;
    conflict.value = null;
    success.value = false;
    busy.value = true;

    try {
        await genreStore.createGenre(name.value.trim());
        name.value = "";
        success.value = true;
    } catch (e) {
        // A name collision is a 409 carrying the genre that already holds the
        // name, not a validation message — say which one, so "why didn't that
        // work" doesn't need a trip to the list.
        if (e.response?.data?.reason_code === "genre_name_taken") {
            conflict.value = e.response.data.conflict;
        } else {
            error.value =
                e.response?.data?.reason ??
                e.response?.data?.message ??
                "An error occurred.";
        }
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <form class="mt-4" @submit.prevent="submit">
        <div class="flex gap-2">
            <label for="new-genre-name" class="sr-only">Genre Name</label>
            <input
                id="new-genre-name"
                v-model="name"
                type="text"
                placeholder="Genre name (e.g. Essay)"
                required
                class="border px-2 py-1 bg-transparent"
            />
            <button
                type="submit"
                class="btn btn-primary shrink-0"
                :disabled="busy"
            >
                Add Genre
            </button>
        </div>
    </form>
    <p v-if="success" class="text-green-600 mt-1">Genre created.</p>
    <p v-if="conflict" class="text-red-600 mt-1">
        <span class="capitalize">{{ conflict.name }}</span> already exists ({{
            conflict.books_count
        }}
        book(s)). Rename it, or merge into it from the list above.
    </p>
    <p v-if="error" class="text-red-600 mt-1">{{ error }}</p>
</template>
