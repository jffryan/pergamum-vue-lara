<script setup>
import { computed, onMounted, ref } from "vue";
import { useGenreStore } from "@/stores";
import {
    LETTERS,
    filterGenres,
    sortGenres,
    groupByLetter,
    countScale,
} from "@/utils/genreList";
import AlertBox from "@/components/globals/alerts/AlertBox.vue";
import GenreCard from "@/components/genres/GenreCard.vue";
import PageLoadingIndicator from "@/components/globals/loading/PageLoadingIndicator.vue";

const genreStore = useGenreStore();

const filterTerm = ref("");
const sortKey = ref("name");
const isLoading = ref(true);
const error = ref("");

onMounted(async () => {
    try {
        // Forced, for the same reason the admin screen forces: `books_count`
        // is not decoration here, it's what the list is ranked and sized by,
        // and the cached copy may predate any book added since the session
        // started. 142 rows — the refetch is cheaper than the staleness.
        await genreStore.fetchAllGenres({ force: true });
    } catch (e) {
        error.value = "Unable to load genres at this time.";
    } finally {
        isLoading.value = false;
    }
});

const allGenres = computed(() => genreStore.allGenres);

// Built from the full list so a bar means the same thing before and during a
// filter.
const scaleCount = computed(() => countScale(allGenres.value));

const visibleGenres = computed(() =>
    sortGenres(filterGenres(allGenres.value, filterTerm.value), sortKey.value),
);

// One shape for both sorts: alphabetical gets letter headings, "most books"
// gets a single unlabelled run, and the template loops once either way.
const sections = computed(() => {
    if (!visibleGenres.value.length) {
        return [];
    }

    if (sortKey.value !== "name") {
        return [{ letter: null, genres: visibleGenres.value }];
    }

    return groupByLetter(visibleGenres.value);
});

const activeLetters = computed(
    () => new Set(sections.value.map((section) => section.letter)),
);

const isFiltered = computed(() => filterTerm.value.trim().length > 0);

const summary = computed(() => {
    const total = allGenres.value.length;

    if (!isFiltered.value) {
        return `${total} genres`;
    }

    return `Showing ${visibleGenres.value.length} of ${total} genres`;
});

const sectionId = (letter) =>
    `genre-letter-${letter === "#" ? "other" : letter}`;

const jumpToLetter = (letter) => {
    document
        .getElementById(sectionId(letter))
        ?.scrollIntoView({ behavior: "smooth", block: "start" });
};
</script>

<template>
    <div>
        <div class="flex flex-wrap items-baseline justify-between gap-x-6">
            <h1 class="mb-2">Genres</h1>
            <router-link
                :to="{ name: 'admin.genres' }"
                class="mb-2 text-sm underline hover:no-underline"
            >
                Manage genres &rarr;
            </router-link>
        </div>

        <PageLoadingIndicator v-if="isLoading" />
        <AlertBox v-else-if="error" :message="error" alert-type="danger" />

        <template v-else>
            <p class="text-zinc-600">{{ summary }}</p>

            <div
                class="sticky top-12 z-30 -mx-1 mb-4 border-b border-zinc-200 bg-zinc-50 px-1 py-3"
            >
                <div class="flex flex-wrap items-center gap-x-6 gap-y-2">
                    <div class="grow max-w-sm">
                        <label for="genre-filter" class="sr-only">
                            Filter genres
                        </label>
                        <input
                            id="genre-filter"
                            v-model="filterTerm"
                            type="text"
                            placeholder="Filter genres…"
                        />
                    </div>
                    <div
                        class="flex items-center gap-1"
                        role="group"
                        aria-label="Sort genres"
                    >
                        <span class="mr-1 text-sm text-zinc-500">Sort</span>
                        <button
                            v-for="option in [
                                { key: 'name', label: 'A–Z' },
                                { key: 'count', label: 'Most books' },
                            ]"
                            :key="option.key"
                            type="button"
                            class="rounded-md border px-3 py-1 text-sm transition-colors"
                            :class="
                                sortKey === option.key
                                    ? 'border-slate-900 bg-slate-900 text-white'
                                    : 'border-zinc-300 hover:border-zinc-500'
                            "
                            :aria-pressed="sortKey === option.key"
                            @click="sortKey = option.key"
                        >
                            {{ option.label }}
                        </button>
                    </div>
                </div>

                <nav
                    v-if="sortKey === 'name'"
                    class="mt-3 flex flex-wrap gap-x-1"
                    aria-label="Jump to letter"
                >
                    <button
                        v-for="letter in LETTERS"
                        :key="letter"
                        type="button"
                        class="w-6 rounded text-sm transition-colors"
                        :class="
                            activeLetters.has(letter)
                                ? 'text-slate-900 hover:bg-slate-900 hover:text-white'
                                : 'text-zinc-300 cursor-default'
                        "
                        :disabled="!activeLetters.has(letter)"
                        @click="jumpToLetter(letter)"
                    >
                        {{ letter }}
                    </button>
                </nav>
            </div>

            <p v-if="!visibleGenres.length">
                No genres match “{{ filterTerm.trim() }}”.
            </p>

            <section
                v-for="section in sections"
                :key="section.letter ?? 'all'"
                :id="section.letter ? sectionId(section.letter) : null"
                class="mb-6 scroll-mt-48"
            >
                <h2
                    v-if="section.letter"
                    class="mb-1 border-b border-zinc-200 pb-1 text-base font-bold text-zinc-500"
                >
                    {{ section.letter }}
                </h2>
                <ul
                    class="grid grid-cols-1 gap-x-4 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4"
                >
                    <GenreCard
                        v-for="genre in section.genres"
                        :key="genre.genre_id"
                        :genre="genre"
                        :bar-width="scaleCount(genre.books_count)"
                    />
                </ul>
            </section>
        </template>
    </div>
</template>
