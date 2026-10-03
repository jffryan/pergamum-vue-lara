<script setup>
import { computed, ref, watch } from "vue";
import { useStatisticsStore } from "@/stores";
import { scopeKey } from "@/stores/StatisticsStore";
import { formatValue } from "@/services/statistics/formatters";
import GenreCard from "@/components/genres/GenreCard.vue";

/**
 * What a genre looks like from the reader's side: how much of it has been
 * read, who writes it, and what else it tends to be tagged with.
 *
 * Fed by the `genre` statistics scope through `StatisticsStore`, but drawn in
 * the library's light, list-first style rather than through `StatisticsGrid`
 * — this sits above a book list, not on a dashboard.
 */
const props = defineProps({
    genreId: {
        type: [Number, String],
        required: true,
    },
    // The listing's own total, so "share of the genre" means the same number
    // the page summary states.
    bookCount: {
        type: Number,
        required: true,
    },
});

const METRICS = [
    "completedCount",
    "completedPercent",
    "averageRating",
    "topAuthors",
    "genreBreakdown",
];

// Enough related genres to show the shape; the rest are behind a toggle.
const RELATED_PREVIEW = 8;

const statisticsStore = useStatisticsStore();
const key = computed(() => scopeKey("genre", props.genreId));
const state = computed(() => statisticsStore.scopeState(key.value));
const metrics = computed(() => statisticsStore.metricsFor(key.value));

const load = () =>
    statisticsStore.fetch("genre", props.genreId, METRICS).catch(() => {
        // The store keeps the error on the scope; the template says so.
    });

// Watching the cache entry rather than the id means any invalidation — a
// book tagged on this page, a read recorded elsewhere — refetches while the
// profile is on screen.
watch(
    () => statisticsStore.scopes[key.value],
    (entry) => {
        if (!entry) load();
    },
    { immediate: true },
);

const hasData = computed(() => Object.keys(metrics.value).length > 0);

const stats = computed(() => {
    const { completedCount, completedPercent, averageRating } = metrics.value;
    const rows = [
        { label: "read", value: formatValue(completedCount) },
        {
            label: "of the genre read",
            value: formatValue(completedPercent, "percent"),
        },
    ];

    if (averageRating !== null && averageRating !== undefined) {
        rows.push({
            label: "average rating",
            value: `★ ${formatValue(averageRating, "rating")}`,
        });
    }

    return rows;
});

const authors = computed(() => metrics.value.topAuthors ?? []);

const showAllRelated = ref(false);
watch(
    () => props.genreId,
    () => {
        showAllRelated.value = false;
    },
);

// Shaped for `GenreCard`, which reads `books_count`. The bar is the share of
// this genre's books that also carry the other tag.
const related = computed(() =>
    (metrics.value.genreBreakdown ?? []).map((row) => ({
        genre_id: row.genre_id,
        name: row.name,
        books_count: row.count,
    })),
);
const visibleRelated = computed(() =>
    showAllRelated.value
        ? related.value
        : related.value.slice(0, RELATED_PREVIEW),
);
const shareOf = (count) =>
    props.bookCount ? Math.min(100, (count / props.bookCount) * 100) : 0;
</script>

<template>
    <div v-if="bookCount > 0" class="mb-8">
        <p v-if="state.error" class="text-sm text-zinc-500">
            Genre statistics are unavailable right now.
        </p>

        <template v-else-if="hasData">
            <dl class="mb-6 flex flex-wrap gap-x-10 gap-y-3">
                <div v-for="stat in stats" :key="stat.label">
                    <dt class="sr-only">{{ stat.label }}</dt>
                    <dd class="text-2xl font-bold tabular-nums">
                        {{ stat.value }}
                    </dd>
                    <dd class="text-sm text-zinc-500" aria-hidden="true">
                        {{ stat.label }}
                    </dd>
                </div>
            </dl>

            <div class="grid gap-x-10 gap-y-6 lg:grid-cols-2">
                <section v-if="authors.length">
                    <h2
                        class="mb-1 border-b border-zinc-200 pb-1 text-base font-bold text-zinc-500"
                    >
                        Top authors
                    </h2>
                    <ol class="divide-y divide-zinc-200">
                        <li
                            v-for="author in authors"
                            :key="author.author_id"
                            class="flex items-baseline justify-between gap-4 py-1.5"
                        >
                            <router-link
                                v-if="author.slug"
                                :to="{
                                    name: 'authors.show',
                                    params: { slug: author.slug },
                                }"
                                class="truncate hover:underline"
                            >
                                {{ author.name }}
                            </router-link>
                            <span v-else class="truncate">
                                {{ author.name }}
                            </span>
                            <span
                                class="shrink-0 text-sm tabular-nums text-zinc-500"
                            >
                                {{ author.count }}
                                {{ author.count === 1 ? "book" : "books" }}
                            </span>
                        </li>
                    </ol>
                </section>

                <section v-if="related.length">
                    <h2
                        class="mb-1 flex items-baseline justify-between gap-4 border-b border-zinc-200 pb-1 text-base font-bold text-zinc-500"
                    >
                        Often tagged with
                        <button
                            v-if="related.length > RELATED_PREVIEW"
                            type="button"
                            class="text-sm font-normal underline hover:no-underline"
                            @click="showAllRelated = !showAllRelated"
                        >
                            {{
                                showAllRelated
                                    ? "Show fewer"
                                    : `Show all ${related.length}`
                            }}
                        </button>
                    </h2>
                    <ul class="grid grid-cols-1 gap-x-4 sm:grid-cols-2">
                        <GenreCard
                            v-for="genre in visibleRelated"
                            :key="genre.genre_id"
                            :genre="genre"
                            :bar-width="shareOf(genre.books_count)"
                        />
                    </ul>
                </section>
            </div>
        </template>
    </div>
</template>
