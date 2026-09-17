<script setup>
import { computed } from "vue";
import {
    primaryAuthor,
    coAuthorCount,
    formatName,
    pageCount,
    latestRead,
    monthYear,
    discardedOn,
} from "@/utils/libraryList";

const props = defineProps({
    book: {
        type: Object,
        required: true,
    },
    // On the discarded shelf the status slot says when the copy went, not
    // when it was last read — the row is answering a different question.
    discarded: {
        type: Boolean,
        default: false,
    },
});

// Enough to place a book; the full list is one click away on its page.
const MAX_GENRES = 3;

const author = computed(() => primaryAuthor(props.book));
const coAuthors = computed(() => coAuthorCount(props.book));

// Format and length, in one muted run after the author. Absent values are
// left out rather than rendered as a blank — most of the library has never
// been read, and a row shouldn't spend space saying so.
const details = computed(() => {
    const pages = pageCount(props.book);

    return [formatName(props.book), pages ? `${pages} pp` : ""].filter(Boolean);
});

const genres = computed(() => props.book.genres?.slice(0, MAX_GENRES) ?? []);
const moreGenres = computed(
    () => (props.book.genres?.length ?? 0) - genres.value.length,
);

const read = computed(() => latestRead(props.book));
const readDate = computed(() => monthYear(read.value?.date_read));
const discardDate = computed(() => monthYear(discardedOn(props.book)));
</script>

<template>
    <li
        class="grid gap-x-6 gap-y-0.5 py-2.5 sm:grid-cols-[minmax(0,5fr)_minmax(0,4fr)_9rem] sm:items-baseline"
    >
        <div class="min-w-0">
            <router-link
                :to="{ name: 'books.show', params: { slug: book.book.slug } }"
                class="font-medium hover:underline"
            >
                {{ book.book.title }}
            </router-link>
            <div class="text-sm text-zinc-500">
                <router-link
                    v-if="author.slug"
                    :to="{
                        name: 'authors.show',
                        params: { slug: author.slug },
                    }"
                    class="hover:underline"
                    >{{ author.name }}</router-link
                ><span v-else>{{ author.name }}</span
                ><span v-if="coAuthors"> +{{ coAuthors }}</span
                ><span v-for="detail in details" :key="detail">
                    · {{ detail }}</span
                >
            </div>
        </div>

        <div class="min-w-0 truncate text-sm capitalize text-zinc-500">
            <template v-for="(genre, index) in genres" :key="genre.genre_id">
                <router-link
                    :to="{
                        name: 'genres.show',
                        params: { id: genre.genre_id },
                    }"
                    class="hover:underline"
                    >{{ genre.name }}</router-link
                ><span v-if="index < genres.length - 1">, </span>
            </template>
            <span v-if="moreGenres > 0" class="normal-case">
                +{{ moreGenres }}
            </span>
        </div>

        <div class="text-sm tabular-nums sm:text-right">
            <template v-if="discarded">
                <span class="text-zinc-500">Discarded</span>
                <span v-if="discardDate"> {{ discardDate }}</span>
            </template>
            <template v-else-if="read">
                <span v-if="read.rating != null" class="text-slate-900"
                    >★ {{ read.rating }}</span
                ><span v-else class="text-zinc-500">Read</span
                ><span v-if="readDate" class="text-zinc-500">
                    · {{ readDate }}</span
                >
            </template>
        </div>
    </li>
</template>
