<script setup>
import GenreRow from "./GenreRow.vue";

defineProps({
    genres: {
        type: Array,
        required: true,
    },
    selectedIds: {
        type: Array,
        required: true,
    },
});

defineEmits(["toggle-select", "merged"]);
</script>

<template>
    <table v-if="genres.length" class="w-full text-left mt-2">
        <thead>
            <tr class="border-b">
                <th scope="col" class="w-8">
                    <span class="sr-only">Select for merge</span>
                </th>
                <th scope="col">Name</th>
                <th scope="col" class="w-20">Books</th>
                <th scope="col" class="w-24">
                    <span class="sr-only">Actions</span>
                </th>
            </tr>
        </thead>
        <tbody>
            <!-- `genre_id`, not `id` — every model here uses a custom PK, and a
                 `:key` bound to a column that doesn't exist is silently undefined. -->
            <GenreRow
                v-for="genre in genres"
                :key="genre.genre_id"
                :genre="genre"
                :selected="selectedIds.includes(genre.genre_id)"
                @toggle-select="$emit('toggle-select', genre.genre_id)"
                @merged="$emit('merged')"
            />
        </tbody>
    </table>
    <p v-else class="mt-2">No genres found.</p>
</template>
