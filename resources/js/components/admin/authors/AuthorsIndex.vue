<script setup>
import { computed, onMounted, ref } from "vue";
import { useAuthorsStore } from "@/stores";
import { filterAuthors } from "@/utils/authorList";
import AuthorRow from "./AuthorRow.vue";
import MergeAuthorsBar from "./MergeAuthorsBar.vue";

// A thousand rows, each with its own edit state, is more than the table needs
// to render at once — the search box is how you get to an author.
const ROW_LIMIT = 100;

const authorsStore = useAuthorsStore();

const searchTerm = ref("");
const selectedIds = ref([]);
const loading = ref(true);
const loadError = ref(null);

onMounted(async () => {
    try {
        // Forced: every count on this screen is a decision input, and the
        // cached list may predate a merge made elsewhere.
        await authorsStore.fetchAllAuthors({ force: true });
    } catch (e) {
        loadError.value = "Unable to load authors at this time.";
    } finally {
        loading.value = false;
    }
});

const filteredAuthors = computed(() =>
    filterAuthors(authorsStore.allAuthors, searchTerm.value),
);

const visibleAuthors = computed(() =>
    filteredAuthors.value.slice(0, ROW_LIMIT),
);

// Resolved against the live list, so ids merged away drop out on their own.
const selectedAuthors = computed(() =>
    authorsStore.allAuthors.filter((author) =>
        selectedIds.value.includes(author.author_id),
    ),
);

function toggleSelection(author_id) {
    if (selectedIds.value.includes(author_id)) {
        selectedIds.value = selectedIds.value.filter((id) => id !== author_id);
    } else {
        selectedIds.value = [...selectedIds.value, author_id];
    }
}

function clearSelection() {
    selectedIds.value = [];
}
</script>

<template>
    <section>
        <h2 class="text-xl mb-2">Authors</h2>
        <p class="text-sm mb-4 max-w-prose">
            Each author is one record shared by every book that credits them, so
            a rename here shows on all of those books at once, and the author's
            page moves to a URL matching the new name. Renaming onto a name
            another author already has offers to merge the two.
        </p>

        <p v-if="loading">Loading authors...</p>
        <p v-else-if="loadError" class="text-red-600">{{ loadError }}</p>

        <template v-else>
            <label for="author-search" class="sr-only">Search authors</label>
            <input
                id="author-search"
                v-model="searchTerm"
                type="search"
                placeholder="Search authors..."
                class="border px-2 py-1 bg-transparent mb-2 w-full max-w-sm"
            />

            <MergeAuthorsBar
                v-if="selectedAuthors.length >= 2"
                :authors="selectedAuthors"
                @merged="clearSelection"
                @cancel="clearSelection"
            />

            <table v-if="visibleAuthors.length" class="w-full text-left mt-2">
                <thead>
                    <tr class="border-b">
                        <th scope="col" class="w-8">
                            <span class="sr-only">Select for merge</span>
                        </th>
                        <th scope="col">Name</th>
                        <th scope="col" class="w-20">Books</th>
                        <th scope="col" class="w-20">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <AuthorRow
                        v-for="author in visibleAuthors"
                        :key="author.author_id"
                        :author="author"
                        :selected="selectedIds.includes(author.author_id)"
                        @toggle-select="toggleSelection(author.author_id)"
                        @merged="clearSelection"
                    />
                </tbody>
            </table>
            <p v-else class="mt-2">No authors found.</p>

            <p
                v-if="filteredAuthors.length > visibleAuthors.length"
                class="text-sm mt-2"
            >
                Showing {{ visibleAuthors.length }} of
                {{ filteredAuthors.length }} — search to narrow the list.
            </p>
        </template>
    </section>
</template>
