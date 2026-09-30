<script setup>
import { computed, ref, watch } from "vue";
import { useAuthorsStore } from "@/stores";
import { authorName } from "@/utils/authorList";
import ConfirmAction from "@/components/globals/ConfirmAction.vue";

const props = defineProps({
    authors: {
        type: Array,
        required: true,
    },
});

const emit = defineEmits(["merged", "cancel"]);

const authorsStore = useAuthorsStore();

// Default the winner to the most-credited of the selection — the canonical
// spelling is usually the one the most books already carry.
const keepId = ref(null);

watch(
    () => props.authors,
    (authors) => {
        if (!authors.some((author) => author.author_id === keepId.value)) {
            keepId.value = [...authors].sort(
                (a, b) => b.books_count - a.books_count,
            )[0]?.author_id;
        }
    },
    { immediate: true },
);

const confirming = ref(false);
const busy = ref(false);
const error = ref(null);

const winner = computed(() =>
    props.authors.find((author) => author.author_id === keepId.value),
);

const losers = computed(() =>
    props.authors.filter((author) => author.author_id !== keepId.value),
);

// "Up to": a book crediting two of the selection is one book, not two. The
// response's `books_count` is the real number, after the fact.
const impact = computed(() => {
    const bookCount = losers.value.reduce(
        (total, author) => total + author.books_count,
        0,
    );

    return `${losers.value.length} author(s) will be deleted permanently, and up to ${bookCount} book(s) credited to "${authorName(winner.value)}" instead. This cannot be undone.`;
});

async function confirmMerge() {
    busy.value = true;
    error.value = null;

    try {
        await authorsStore.mergeAuthors(
            keepId.value,
            losers.value.map((author) => author.author_id),
        );
        confirming.value = false;
        emit("merged");
    } catch (e) {
        error.value =
            e.response?.data?.reason ??
            e.response?.data?.message ??
            "Unable to merge those authors.";
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <div class="border p-3 mt-2 text-sm">
        <div class="flex gap-2 items-center flex-wrap">
            <span>{{ authors.length }} authors selected — merge into</span>
            <label for="merge-author-winner" class="sr-only">
                Author to merge into
            </label>
            <select
                id="merge-author-winner"
                v-model="keepId"
                class="border px-2 py-1 bg-transparent"
            >
                <option
                    v-for="author in authors"
                    :key="author.author_id"
                    :value="author.author_id"
                >
                    {{ authorName(author) }} ({{ author.books_count }})
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
            :title="`Merge ${losers.length} author(s) into &quot;${authorName(winner)}&quot;?`"
            :impact="impact"
            confirm-label="Merge authors"
            :busy="busy"
            @confirm="confirmMerge"
            @cancel="confirming = false"
        />

        <p v-if="error" class="text-red-600 mt-1">{{ error }}</p>
    </div>
</template>
