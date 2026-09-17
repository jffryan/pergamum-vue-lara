<script setup>
import { computed, onMounted, ref, watch } from "vue";
import { useRoute } from "vue-router";
import { useBooksStore, useListsStore } from "@/stores";
import { fetchBookData } from "@/services/BookServices";
import { getAllLists } from "@/api/ListController";
import { discardVersion, restoreVersion } from "@/api/VersionController";
import { setVersionLocation } from "@/api/LocationController";
import {
    authorList,
    copySummary,
    listsHolding,
    orderedCopies,
    readingLine,
    readsByCopy,
    readsFor,
} from "@/utils/bookDetail";
import AlertBox from "@/components/globals/alerts/AlertBox.vue";
import PageLoadingIndicator from "@/components/globals/loading/PageLoadingIndicator.vue";
import LibraryBookRow from "@/components/library/LibraryBookRow.vue";
import BookCopyRow from "@/components/books/detail/BookCopyRow.vue";
import BookReadRow from "@/components/books/detail/BookReadRow.vue";

const route = useRoute();
const booksStore = useBooksStore();
const listsStore = useListsStore();

// --- Data -------------------------------------------------------------------

const hasLoaded = ref(false);
const error = ref("");
const versionActionError = ref("");

const slug = computed(() => route.params.slug);

/**
 * The store holds two shapes of book: the card-shaped rows a library page
 * leaves behind, and the full detail payload. Only the second can render this
 * page, and `authorRelatedBooks` is the key only the second has — so the page
 * gates on it rather than on "is this book in the store", which is what let
 * the old view render a half-loaded neighbour and throw on `book.title`.
 */
const detail = computed(() => {
    const entry = booksStore.allBooks.find((b) => b.book.slug === slug.value);

    return entry && "authorRelatedBooks" in entry ? entry : null;
});

const load = async () => {
    const requested = slug.value;

    error.value = "";
    versionActionError.value = "";

    if (detail.value) {
        hasLoaded.value = true;
        return;
    }

    hasLoaded.value = false;

    try {
        const data = await fetchBookData(requested);

        // `fetchBookData` rejects on failure; the old view tested its return
        // value with `instanceof Error`, a branch that could never be taken,
        // so a failed load sat on the spinner forever.
        if (slug.value !== requested) return;

        if (booksStore.allBooks.some((b) => b.book.slug === requested)) {
            booksStore.updateBook(data);
        } else {
            booksStore.addBook(data);
        }
    } catch (e) {
        console.error("Error fetching book data:", e);

        if (slug.value === requested) {
            error.value = "Unable to load this book. Please try again later.";
        }
    } finally {
        if (slug.value === requested) {
            hasLoaded.value = true;
        }
    }
};

// Immediate, and re-runs on every slug change: "More by this author" links to
// another book page, which is the same component with different params.
watch(slug, load, { immediate: true });

// Lists gate one section, not the page — it renders without them and gains the
// section when they arrive. The store keeps them for the session.
onMounted(async () => {
    if (listsStore.allLists.length > 0) return;

    try {
        const res = await getAllLists();
        listsStore.setAllLists(res.data);
    } catch (e) {
        console.error("Error fetching lists:", e);
    }
});

// --- Presentation -----------------------------------------------------------

const title = computed(() => detail.value?.book.title ?? "");
const authors = computed(() => authorList(detail.value));
const genres = computed(() => detail.value?.genres ?? []);

const reading = computed(() => readingLine(detail.value));
const reads = computed(() => readsFor(detail.value));

const copies = computed(() => orderedCopies(detail.value?.versions));
const copyCount = computed(() => copySummary(detail.value?.versions));
const copyReads = computed(() => readsByCopy(detail.value));

const lists = computed(() => listsHolding(detail.value, listsStore.allLists));
// Lists reference a copy, not a book, so naming the copy only earns its space
// once there is more than one copy to tell apart.
const showListCopies = computed(() => copies.value.length > 1);

const related = computed(() => detail.value?.authorRelatedBooks ?? []);
const relatedHeading = computed(() =>
    authors.value.length === 1
        ? `More by ${authors.value[0].name}`
        : "More by these authors",
);

// --- Version actions --------------------------------------------------------

const applyVersionAction = async (request, message) => {
    versionActionError.value = "";

    try {
        const res = await request();
        booksStore.replaceVersion(detail.value.book.book_id, res.data);
    } catch (e) {
        console.error("Error updating version:", e);
        versionActionError.value = message;
    }
};

const discardCopy = ({ version_id, discarded_at }) =>
    applyVersionAction(
        () => discardVersion(version_id, discarded_at),
        "Unable to discard this copy. Please try again.",
    );

const restoreCopy = (version_id) =>
    applyVersionAction(
        () => restoreVersion(version_id),
        "Unable to restore this copy. Please try again.",
    );

const moveCopy = ({ version_id, location_id }) =>
    applyVersionAction(
        () => setVersionLocation(version_id, location_id),
        "Unable to move this copy. Please try again.",
    );
</script>

<template>
    <div>
        <PageLoadingIndicator v-if="!hasLoaded" />
        <AlertBox v-else-if="error" :message="error" alert-type="danger" />

        <template v-else-if="detail">
            <router-link
                :to="{ name: 'library.index' }"
                class="text-sm text-zinc-500 underline hover:no-underline"
            >
                &larr; Library
            </router-link>

            <!-- Identity: what the book is, and what you can do to it. The
                 actions are a quiet run of links rather than three filled
                 buttons — nothing here is the page's primary purpose. -->
            <div
                class="mt-2 flex flex-wrap items-baseline justify-between gap-x-6"
            >
                <h1 class="mb-1">{{ title }}</h1>
                <p class="mb-1 text-sm text-zinc-500">
                    <router-link
                        :to="{ name: 'books.edit', params: { slug } }"
                        class="underline hover:no-underline"
                        >Edit</router-link
                    >
                    &middot;
                    <router-link
                        :to="{
                            name: 'books.add-read-history',
                            params: { slug },
                        }"
                        class="underline hover:no-underline"
                        >Add a read</router-link
                    >
                    &middot;
                    <router-link
                        :to="{ name: 'books.add-version', params: { slug } }"
                        class="underline hover:no-underline"
                        >Add a copy</router-link
                    >
                </p>
            </div>

            <p class="mb-0 text-zinc-600">
                <template
                    v-for="(author, index) in authors"
                    :key="author.author_id"
                >
                    <router-link
                        v-if="author.slug"
                        :to="{
                            name: 'authors.show',
                            params: { slug: author.slug },
                        }"
                        class="hover:underline"
                        >{{ author.name }}</router-link
                    ><span v-else>{{ author.name }}</span
                    ><span v-if="index < authors.length - 1">, </span>
                </template>
                <span v-if="!authors.length" class="text-zinc-400"
                    >Unknown author</span
                >
            </p>

            <p
                v-if="genres.length"
                class="mb-0 text-sm capitalize text-zinc-500"
            >
                <template
                    v-for="(genre, index) in genres"
                    :key="genre.genre_id"
                >
                    <router-link
                        :to="{
                            name: 'genres.show',
                            params: { id: genre.genre_id },
                        }"
                        class="hover:underline"
                        >{{ genre.name }}</router-link
                    ><span v-if="index < genres.length - 1">, </span>
                </template>
            </p>

            <!-- The answer the page exists to give, in one line. -->
            <p class="mb-8 mt-3 tabular-nums">{{ reading }}</p>

            <section v-if="reads.length" class="mb-8">
                <h2
                    class="mb-1 border-b border-zinc-200 pb-1 text-base font-bold text-zinc-500"
                >
                    Reading history
                </h2>
                <ul class="divide-y divide-zinc-200">
                    <BookReadRow
                        v-for="read in reads"
                        :key="read.read_instance_id"
                        :read="read"
                    />
                </ul>
            </section>

            <section class="mb-8">
                <h2
                    class="mb-1 flex flex-wrap items-baseline justify-between gap-x-4 border-b border-zinc-200 pb-1 text-base font-bold text-zinc-500"
                >
                    Copies
                    <span class="font-normal">{{ copyCount }}</span>
                </h2>

                <p v-if="!copies.length" class="py-2.5 text-zinc-500">
                    No copies recorded.
                    <router-link
                        :to="{ name: 'books.add-version', params: { slug } }"
                        class="underline hover:no-underline"
                        >Add one</router-link
                    >.
                </p>
                <ul v-else class="divide-y divide-zinc-200">
                    <BookCopyRow
                        v-for="copy in copies"
                        :key="copy.version_id"
                        :version="copy"
                        :read-count="copyReads.get(copy.version_id) ?? 0"
                        @discard="discardCopy"
                        @restore="restoreCopy"
                        @move="moveCopy"
                    />
                </ul>

                <AlertBox
                    v-if="versionActionError"
                    :message="versionActionError"
                    alert-type="danger"
                    class="mt-2"
                />
            </section>

            <section v-if="lists.length" class="mb-8">
                <h2
                    class="mb-1 border-b border-zinc-200 pb-1 text-base font-bold text-zinc-500"
                >
                    In your lists
                </h2>
                <ul class="divide-y divide-zinc-200">
                    <li
                        v-for="entry in lists"
                        :key="entry.list.list_id"
                        class="flex flex-wrap items-baseline justify-between gap-x-6 py-2.5"
                    >
                        <router-link
                            :to="{
                                name: 'lists.show',
                                params: { id: entry.list.list_id },
                            }"
                            class="hover:underline"
                            >{{ entry.list.name }}</router-link
                        >
                        <span
                            v-if="showListCopies"
                            class="text-sm text-zinc-500"
                            >{{ entry.copies.join(", ") }}</span
                        >
                    </li>
                </ul>
            </section>

            <!-- Related books render as library rows: the same component, the
                 same shape, so a book reads the same wherever you meet it. -->
            <section v-if="related.length">
                <h2
                    class="mb-1 border-b border-zinc-200 pb-1 text-base font-bold text-zinc-500"
                >
                    {{ relatedHeading }}
                </h2>
                <ul class="divide-y divide-zinc-200">
                    <LibraryBookRow
                        v-for="entry in related"
                        :key="entry.book.book_id"
                        :book="entry"
                    />
                </ul>
            </section>
        </template>
    </div>
</template>
