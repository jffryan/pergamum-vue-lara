<script setup>
import { computed, onMounted, ref } from "vue";
import { useGenreStore } from "@/stores";
import GenresTable from "./GenresTable.vue";
import CreateGenre from "./CreateGenre.vue";
import MergeGenresBar from "./MergeGenresBar.vue";

const genreStore = useGenreStore();

const searchTerm = ref("");
const selectedIds = ref([]);
const loading = ref(true);
const loadError = ref(null);

onMounted(async () => {
    try {
        // Forced: the cached list may have been sitting in the store since
        // before someone else's merge, and every count on this screen is a
        // decision input.
        await genreStore.fetchAllGenres({ force: true });
    } catch (e) {
        loadError.value = "Unable to load genres at this time.";
    } finally {
        loading.value = false;
    }
});

// A plain filtered table rather than a copy of GenresView's search + sort +
// pagination. Duplicating that would mean two implementations to keep honest;
// extracting it would mean refactoring a working user-facing view to serve an
// admin screen. Revisit if the genre count makes this unusable.
const filteredGenres = computed(() => {
    const term = searchTerm.value.trim().toLowerCase();

    if (!term) {
        return genreStore.allGenres;
    }

    return genreStore.allGenres.filter((genre) =>
        genre.name.toLowerCase().includes(term),
    );
});

// Resolved against the live list rather than held as objects, so ids that
// stopped existing (merged away, deleted) drop out of the selection on their own.
const selectedGenres = computed(() =>
    genreStore.allGenres.filter((genre) =>
        selectedIds.value.includes(genre.genre_id),
    ),
);

function toggleSelection(genre_id) {
    if (selectedIds.value.includes(genre_id)) {
        selectedIds.value = selectedIds.value.filter((id) => id !== genre_id);
    } else {
        selectedIds.value = [...selectedIds.value, genre_id];
    }
}

function clearSelection() {
    selectedIds.value = [];
}
</script>

<template>
    <section>
        <h2 class="text-xl mb-4">Genres</h2>

        <p v-if="loading">Loading genres...</p>
        <p v-else-if="loadError" class="text-red-600">{{ loadError }}</p>

        <template v-else>
            <label for="genre-search" class="sr-only">Search genres</label>
            <input
                id="genre-search"
                v-model="searchTerm"
                type="text"
                placeholder="Search genres..."
                class="border px-2 py-1 bg-transparent mb-2"
            />

            <MergeGenresBar
                v-if="selectedGenres.length >= 2"
                :genres="selectedGenres"
                @merged="clearSelection"
                @cancel="clearSelection"
            />

            <GenresTable
                :genres="filteredGenres"
                :selected-ids="selectedIds"
                @toggle-select="toggleSelection"
                @merged="clearSelection"
            />

            <CreateGenre />
        </template>
    </section>
</template>
