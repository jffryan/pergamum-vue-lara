<script setup>
import { ref } from "vue";
import { getAllBooks } from "@/api/BookController";

/**
 * Title search that offers each matching book as add buttons. Owns the search
 * round-trip; what "add" means belongs to the owning view, which listens for
 * `add`.
 *
 * `mode` is what gets added. `version` (lists, shelves — things that hold
 * physical copies) renders one button per copy and emits the version;
 * `isVersionAdded` disables the ones already present. `book` (genres —
 * things that attach to the work) renders one button per result and emits
 * the whole result row; `isBookAdded` disables it. Either way the emitted
 * object is exactly what `/api/books?search=` returns.
 */
const props = defineProps({
    mode: {
        type: String,
        default: "version",
        validator: (value) => ["version", "book"].includes(value),
    },
    isVersionAdded: {
        type: Function,
        default: () => false,
    },
    isBookAdded: {
        type: Function,
        default: () => false,
    },
});

const emit = defineEmits(["add"]);

const searchTerm = ref("");
const searchResults = ref([]);
const isSearching = ref(false);
const hasSearched = ref(false);

const searchForBook = async () => {
    if (!searchTerm.value.trim()) return;
    isSearching.value = true;
    hasSearched.value = false;
    try {
        const res = await getAllBooks({
            search: searchTerm.value.trim(),
        });
        searchResults.value = res.data.books || [];
        hasSearched.value = true;
    } catch (error) {
        console.error("Error searching books:", error);
    } finally {
        isSearching.value = false;
    }
};

const primaryAuthor = (result) => {
    const author = result.authors[0];
    if (!author) return null;
    return `${author.first_name || ""} ${author.last_name || ""}`.trim();
};
</script>

<template>
    <div>
        <h2 class="text-lg font-semibold mb-2">Add a book</h2>
        <form
            @submit.prevent="searchForBook"
            class="flex flex-col sm:flex-row gap-2 mb-3"
        >
            <input
                v-model="searchTerm"
                type="text"
                placeholder="Search by title..."
                class="flex-1 bg-zinc-50 border border-gray-400 rounded px-2 py-1"
            />
            <button
                type="submit"
                class="bg-slate-900 text-white rounded px-3 py-2 hover:bg-slate-700 sm:py-1"
                :disabled="isSearching"
            >
                Search
            </button>
        </form>
        <div v-if="searchResults.length > 0">
            <div
                v-for="result in searchResults"
                :key="result.book.book_id"
                class="mb-3 border border-gray-200 rounded p-3"
            >
                <div class="font-medium mb-2">
                    {{ result.book.title }}
                    <span
                        class="text-gray-500 font-normal text-sm"
                        v-if="primaryAuthor(result)"
                    >
                        — {{ primaryAuthor(result) }}
                    </span>
                </div>
                <div v-if="props.mode === 'book'">
                    <button
                        @click="emit('add', result)"
                        :disabled="props.isBookAdded(result)"
                        class="text-sm border rounded px-2 py-1"
                        :class="
                            props.isBookAdded(result)
                                ? 'border-gray-300 text-gray-400 cursor-default'
                                : 'border-slate-900 hover:bg-slate-900 hover:text-white'
                        "
                    >
                        {{ props.isBookAdded(result) ? "✓ Added" : "Add" }}
                    </button>
                </div>
                <div v-else class="flex flex-wrap gap-2">
                    <button
                        v-for="version in result.versions"
                        :key="version.version_id"
                        @click="emit('add', version)"
                        :disabled="props.isVersionAdded(version)"
                        class="text-sm border rounded px-2 py-1"
                        :class="
                            props.isVersionAdded(version)
                                ? 'border-gray-300 text-gray-400 cursor-default'
                                : 'border-slate-900 hover:bg-slate-900 hover:text-white'
                        "
                    >
                        {{ version.format.name }}
                        <span
                            v-if="version.page_count"
                            class="text-xs opacity-70"
                        >
                            ({{ version.page_count }}pp)
                        </span>
                        <span
                            v-if="props.isVersionAdded(version)"
                            class="text-xs"
                        >
                            ✓ Added
                        </span>
                    </button>
                </div>
            </div>
        </div>
        <div
            v-else-if="hasSearched && !isSearching"
            class="text-gray-500 text-sm"
        >
            No books found.
        </div>
    </div>
</template>
