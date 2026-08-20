<script setup>
defineProps({
    genre: {
        type: Object,
        required: true,
    },
    // Percentage, already scaled by the caller against the *whole* list.
    barWidth: {
        type: Number,
        required: true,
    },
});
</script>

<template>
    <li>
        <router-link
            :to="{ name: 'genres.show', params: { id: genre.genre_id } }"
            class="block rounded-md border border-transparent px-3 py-2 hover:border-zinc-300 hover:bg-white focus-visible:border-zinc-300 focus-visible:bg-white transition-colors"
        >
            <span class="flex items-baseline justify-between gap-2">
                <span class="capitalize truncate">{{ genre.name }}</span>
                <span class="shrink-0 text-sm text-zinc-500 tabular-nums">
                    {{ genre.books_count }}<span class="sr-only"> books</span>
                </span>
            </span>
            <!-- Decorative: the number above is the accessible value. -->
            <span
                aria-hidden="true"
                class="mt-1.5 block h-1 rounded-full bg-zinc-200"
            >
                <span
                    class="block h-1 rounded-full bg-slate-700"
                    :style="{ width: `${barWidth}%` }"
                />
            </span>
        </router-link>
    </li>
</template>
