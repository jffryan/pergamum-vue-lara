<script setup>
import { computed, ref, watch } from "vue";
import { useGenreStore } from "@/stores";
import ConfirmAction from "@/components/globals/ConfirmAction.vue";

const props = defineProps({
    genres: {
        type: Array,
        required: true,
    },
});

const emit = defineEmits(["merged", "cancel"]);

const genreStore = useGenreStore();

// Default the winner to the most-used of the selection — the canonical spelling
// is usually the one the most books already carry.
const keepId = ref(null);

watch(
    () => props.genres,
    (genres) => {
        if (!genres.some((genre) => genre.genre_id === keepId.value)) {
            keepId.value = [...genres].sort(
                (a, b) => b.books_count - a.books_count,
            )[0]?.genre_id;
        }
    },
    { immediate: true },
);

const confirming = ref(false);
const busy = ref(false);
const error = ref(null);

const winner = computed(() =>
    props.genres.find((genre) => genre.genre_id === keepId.value),
);

const losers = computed(() =>
    props.genres.filter((genre) => genre.genre_id !== keepId.value),
);

// Deliberately "up to": books tagged with two of the selected genres are one
// book, not two, and the client can't tell which overlap without asking. The
// response's `books_count` is the real number, after the fact.
const impact = computed(() => {
    const bookCount = losers.value.reduce(
        (total, genre) => total + genre.books_count,
        0,
    );

    return `${losers.value.length} genre(s) will be deleted permanently, and up to ${bookCount} book(s) re-tagged as "${winner.value?.name}". This cannot be undone.`;
});

async function confirmMerge() {
    busy.value = true;
    error.value = null;

    try {
        await genreStore.mergeGenres(
            keepId.value,
            losers.value.map((genre) => genre.genre_id),
        );
        confirming.value = false;
        emit("merged");
    } catch (e) {
        error.value =
            e.response?.data?.reason ??
            e.response?.data?.message ??
            "Unable to merge those genres.";
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="border p-3 mt-2 text-sm">
        <div class="flex gap-2 items-center flex-wrap">
            <span>{{ genres.length }} genres selected — merge into</span>
            <label for="merge-winner" class="sr-only"
                >Genre to merge into</label
            >
            <select
                id="merge-winner"
                v-model="keepId"
                class="border px-2 py-1 bg-transparent capitalize"
            >
                <option
                    v-for="genre in genres"
                    :key="genre.genre_id"
                    :value="genre.genre_id"
                >
                    {{ genre.name }} ({{ genre.books_count }})
                </option>
            </select>
            <button
                type="button"
                class="btn btn-primary"
                :disabled="busy || !losers.length"
                @click="confirming = true"
            >
                Merge
            </button>
            <button type="button" class="underline" @click="$emit('cancel')">
                Clear selection
            </button>
        </div>

        <ConfirmAction
            v-if="confirming"
            :title="`Merge ${losers.length} genre(s) into &quot;${winner?.name}&quot;?`"
            :impact="impact"
            confirm-label="Merge genres"
            :busy="busy"
            @confirm="confirmMerge"
            @cancel="confirming = false"
        />

        <p v-if="error" class="text-red-600 mt-1">{{ error }}</p>
    </div>
</template>
