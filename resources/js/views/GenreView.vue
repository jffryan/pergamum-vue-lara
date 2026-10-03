<script setup>
import { computed, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { bulkTagBooks } from "@/api/BookController";
import { getOneGenre } from "@/api/GenresController";
import { useBooksStore, useStatisticsStore } from "@/stores";
import {
    DEFAULT_SORT,
    DEFAULT_PAGE_SIZE,
    PAGE_SIZES,
    groupBooks,
    summarize,
} from "@/utils/libraryList";
import AddBookSearch from "@/components/books/AddBookSearch.vue";
import AlertBox from "@/components/globals/alerts/AlertBox.vue";
import GenreProfile from "@/components/genres/GenreProfile.vue";
import PageLoadingIndicator from "@/components/globals/loading/PageLoadingIndicator.vue";
import LibraryBookRow from "@/components/library/LibraryBookRow.vue";
import LibraryPagination from "@/components/library/LibraryPagination.vue";

const route = useRoute();
const router = useRouter();
const booksStore = useBooksStore();
const statisticsStore = useStatisticsStore();

// Paging goes through the URL, as on the library, so a page is bookmarkable
// and the back button walks it.
const genreId = computed(() => route.params.id);
const page = computed(() => Number(route.query.page) || 1);
const limit = computed(() => {
    const value = Number(route.query.limit);

    return PAGE_SIZES.includes(value) ? value : DEFAULT_PAGE_SIZE;
});

// Defaults stay out of the URL so the common case is a clean `/genres/:id`.
const buildQuery = (overrides = {}) => {
    const merged = { page: page.value, limit: limit.value, ...overrides };

    if (merged.page === 1) delete merged.page;
    if (merged.limit === DEFAULT_PAGE_SIZE) delete merged.limit;

    return merged;
};

const linkFor = (target) => ({
    name: "genres.show",
    params: { id: genreId.value },
    query: buildQuery({ page: target }),
});

const changePageSize = (size) =>
    router.push({
        name: "genres.show",
        params: { id: genreId.value },
        query: buildQuery({ page: 1, limit: size }),
    });

// --- Data -------------------------------------------------------------------

const hasLoaded = ref(false);
const isLoading = ref(true);
const error = ref("");
const genre = ref(null);
const books = ref([]);
const pagination = ref(null);

// The endpoint takes no `?sort=` yet and orders by the library's default, so
// the sections are that sort's — author letters.
const sections = computed(() => groupBooks(books.value, DEFAULT_SORT));
const summary = computed(() =>
    pagination.value ? summarize({ total: pagination.value.total }) : "",
);

const fetchData = async () => {
    isLoading.value = true;
    error.value = "";

    try {
        const res = await getOneGenre(genreId.value, {
            page: page.value,
            limit: limit.value,
        });

        if (!res.data || res.status !== 200) {
            throw new Error("Invalid response from the server");
        }

        genre.value = res.data.genre;
        books.value = res.data.books;
        pagination.value = res.data.pagination;
    } catch (e) {
        console.error("Error fetching books:", e);
        error.value =
            "Unable to load books at this time. Please try again later.";
    } finally {
        isLoading.value = false;
        hasLoaded.value = true;
    }
};

// --- Adding books -----------------------------------------------------------

// Search results are a snapshot, so a book tagged from them still reads
// untagged there; this is what flips its button to Added.
const taggedBookIds = ref(new Set());
const addError = ref("");

const isBookAdded = (result) =>
    taggedBookIds.value.has(result.book.book_id) ||
    result.genres.some((g) => g.genre_id === genre.value.genre_id);

// By id, not name: the page already holds the row, and a name could
// re-resolve onto a near-duplicate. See `BulkTagBooksRequest`.
const addBook = async (result) => {
    const bookId = result.book.book_id;
    addError.value = "";

    try {
        await bulkTagBooks([bookId], {
            genre_ids: [genre.value.genre_id],
        });
        taggedBookIds.value.add(bookId);
        booksStore.addGenres([bookId], [genre.value]);
        // A new tag moves this genre's numbers and any list or shelf genre
        // breakdown the book appears in, so drop every cached scope.
        statisticsStore.invalidateAll();
        await fetchData();
    } catch (e) {
        console.error("Error tagging book:", e);
        addError.value = `Couldn't add "${result.book.title}" to this genre.`;
    }
};

// A different genre is a different page: start over rather than carrying the
// last one's tagged set and header into it.
watch(
    genreId,
    () => {
        hasLoaded.value = false;
        taggedBookIds.value = new Set();
        addError.value = "";
    },
    { immediate: true },
);

watch([genreId, page, limit], fetchData, { immediate: true });
</script>

<template>
    <div>
        <PageLoadingIndicator v-if="!hasLoaded" />
        <AlertBox v-else-if="error" :message="error" alert-type="danger" />

        <template v-else>
            <div class="flex flex-wrap items-baseline justify-between gap-x-6">
                <h1 class="mb-2 capitalize">{{ genre.name }}</h1>
                <router-link
                    :to="{ name: 'genres.index' }"
                    class="mb-2 text-sm underline hover:no-underline"
                >
                    &larr; All genres
                </router-link>
            </div>

            <p class="mb-4 text-zinc-600">{{ summary }}</p>

            <GenreProfile
                :genre-id="genre.genre_id"
                :book-count="pagination.total"
            />

            <!-- Later fetches (paging, a book added below) keep the list in
                 place rather than swapping it for a spinner. -->
            <div :aria-busy="isLoading">
                <p v-if="!books.length" class="py-6">
                    No books in this genre yet.
                </p>

                <section
                    v-for="(section, index) in sections"
                    :key="`${index}-${section.label}`"
                    class="mb-6"
                >
                    <h2
                        class="mb-1 border-b border-zinc-200 pb-1 text-base font-bold text-zinc-500"
                    >
                        {{ section.label }}
                    </h2>
                    <ul class="divide-y divide-zinc-200">
                        <LibraryBookRow
                            v-for="book in section.books"
                            :key="book.book.book_id"
                            :book="book"
                        />
                    </ul>
                </section>

                <LibraryPagination
                    v-if="pagination"
                    :pagination="pagination"
                    :page-size="limit"
                    :link-for="linkFor"
                    @page-size="changePageSize"
                />
            </div>

            <div class="mt-10 border-t border-zinc-200 pt-6">
                <AddBookSearch
                    mode="book"
                    :is-book-added="isBookAdded"
                    @add="addBook"
                />
                <AlertBox
                    v-if="addError"
                    class="mt-2"
                    :message="addError"
                    alert-type="danger"
                />
            </div>
        </template>
    </div>
</template>
