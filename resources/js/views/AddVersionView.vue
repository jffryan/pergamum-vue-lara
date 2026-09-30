<script setup>
import { computed, onMounted, ref, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { useBooksStore, useConfigStore } from "@/stores";
import { fetchBookData } from "@/services/BookServices";
import { createVersion } from "@/api/VersionController";
import {
    authorList,
    copyState,
    formatLabel,
    lengthLabel,
    locationLabel,
    orderedCopies,
} from "@/utils/bookDetail";
import {
    copyPayload,
    emptyCopy,
    saveErrorMessage,
    validateCopy,
} from "@/utils/copyForm";
import AlertBox from "@/components/globals/alerts/AlertBox.vue";
import PageLoadingIndicator from "@/components/globals/loading/PageLoadingIndicator.vue";
import CopyFields from "@/components/books/CopyFields.vue";

/**
 * Add another copy of a book that exists — the audiobook as well as the
 * paperback, a second paperback for the other shelf.
 *
 * The copy is entered with the same `CopyFields` as the new-book page, shelf
 * included, and posted to `POST /versions`, which shelves it through
 * `LocationService::shelveVersion`. The book's existing copies are listed
 * above the form so a duplicate is visible before it's made.
 */
const route = useRoute();
const router = useRouter();
const booksStore = useBooksStore();
const ConfigStore = useConfigStore();

const slug = computed(() => route.params.slug);

// --- Loading ----------------------------------------------------------------

const hasLoaded = ref(false);
const loadError = ref("");

// The detail payload, not a library row: the same gate `BookView` uses, so
// the copy appended below shows up on the book page without a refetch.
const detail = computed(() => {
    const entry = booksStore.allBooks.find((b) => b.book.slug === slug.value);

    return entry && "authorRelatedBooks" in entry ? entry : null;
});

const load = async () => {
    const requested = slug.value;

    loadError.value = "";

    if (detail.value) {
        hasLoaded.value = true;
        return;
    }

    hasLoaded.value = false;

    try {
        const data = await fetchBookData(requested);

        if (slug.value !== requested) return;

        if (booksStore.allBooks.some((b) => b.book.slug === requested)) {
            booksStore.updateBook(data);
        } else {
            booksStore.addBook(data);
        }
    } catch (e) {
        console.error("Error fetching book data:", e);

        if (slug.value === requested) {
            loadError.value =
                "Unable to load this book. Please try again later.";
        }
    } finally {
        if (slug.value === requested) {
            hasLoaded.value = true;
        }
    }
};

watch(slug, load, { immediate: true });

onMounted(() => {
    ConfigStore.checkForFormats();
});

const title = computed(() => detail.value?.book.title ?? "");
const authors = computed(() =>
    authorList(detail.value)
        .map((author) => author.name)
        .join(", "),
);
const copies = computed(() => orderedCopies(detail.value?.versions));

const whereIs = (copy) => {
    if (copyState(copy) === "discarded") return "Discarded";

    return locationLabel(copy) || "Unshelved";
};

// --- Form -------------------------------------------------------------------

const formats = computed(() => ConfigStore.books.formats);
const copy = ref(emptyCopy());
const errors = ref({});
// Errors appear after the first submit, then track every edit.
const attempted = ref(false);
const isSaving = ref(false);
const saveError = ref("");

const updateCopy = (next) => {
    copy.value = next;

    if (attempted.value) {
        errors.value = validateCopy(copy.value, formats.value);
    }
};

const submit = async () => {
    attempted.value = true;
    saveError.value = "";
    errors.value = validateCopy(copy.value, formats.value);

    if (Object.keys(errors.value).length || isSaving.value) return;

    isSaving.value = true;

    try {
        const res = await createVersion({
            book_id: detail.value.book.book_id,
            ...copyPayload(copy.value, formats.value),
        });

        // The server's row — with its id, `format` and `location` — not the
        // draft, so the book page renders it like any other copy.
        detail.value.versions.push(res.data);

        router.push({ name: "books.show", params: { slug: slug.value } });
    } catch (error) {
        console.error("Error adding copy:", error);
        saveError.value = saveErrorMessage(error, "copy");
        isSaving.value = false;
    }
};

const sectionHeading =
    "mb-3 border-b border-zinc-200 pb-1 text-base font-bold text-zinc-500";
const action = "underline hover:no-underline";
</script>

<template>
    <div class="max-w-2xl">
        <PageLoadingIndicator v-if="!hasLoaded" />
        <AlertBox
            v-else-if="loadError"
            :message="loadError"
            alert-type="danger"
        />

        <template v-else-if="detail">
            <router-link
                :to="{ name: 'books.show', params: { slug } }"
                class="text-sm text-zinc-500 underline hover:no-underline"
            >
                &larr; {{ title }}
            </router-link>

            <h1 class="mb-1 mt-2">Add a copy</h1>
            <p class="mb-8 text-zinc-500">
                <span class="text-zinc-700">{{ title }}</span>
                <template v-if="authors"> — {{ authors }}</template>
            </p>

            <section v-if="copies.length" class="mb-8">
                <h2 :class="sectionHeading">Copies you already have</h2>
                <ul class="divide-y divide-zinc-200">
                    <li
                        v-for="existing in copies"
                        :key="existing.version_id"
                        class="flex flex-wrap items-baseline justify-between gap-x-6 py-2"
                        :class="
                            copyState(existing) === 'discarded'
                                ? 'text-zinc-500'
                                : ''
                        "
                    >
                        <span>
                            {{ formatLabel(existing)
                            }}<span
                                v-if="lengthLabel(existing)"
                                class="text-zinc-500"
                            >
                                · {{ lengthLabel(existing) }}</span
                            ><span
                                v-if="existing.nickname"
                                class="text-zinc-500"
                            >
                                · “{{ existing.nickname }}”</span
                            >
                        </span>
                        <span class="text-sm text-zinc-500">{{
                            whereIs(existing)
                        }}</span>
                    </li>
                </ul>
            </section>

            <form novalidate @submit.prevent="submit">
                <section class="mb-8">
                    <h2 :class="sectionHeading">New copy</h2>
                    <CopyFields
                        :copy="copy"
                        :errors="errors"
                        id-prefix="add-copy"
                        @update:copy="updateCopy"
                    />
                </section>

                <AlertBox
                    v-if="saveError"
                    :message="saveError"
                    alert-type="danger"
                />

                <div class="flex items-center gap-4">
                    <button
                        type="submit"
                        class="btn btn-primary"
                        :disabled="isSaving"
                    >
                        {{ isSaving ? "Saving…" : "Add copy" }}
                    </button>
                    <router-link
                        :to="{ name: 'books.show', params: { slug } }"
                        :class="[action, 'text-sm text-zinc-500']"
                        >Cancel</router-link
                    >
                </div>
            </form>
        </template>
    </div>
</template>
