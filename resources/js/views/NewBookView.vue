<script setup>
import { computed, onMounted, ref } from "vue";
import { useRouter } from "vue-router";
import { useConfigStore } from "@/stores";
import { createOrGetBookByTitle, submitNewBook } from "@/api/BookController";
import {
    canAddAuthor,
    emptyAuthor,
    emptyDraft,
    matchAuthorLine,
    submitErrorMessage,
    toPayload,
    validateDraft,
} from "@/utils/newBookForm";
import AlertBox from "@/components/globals/alerts/AlertBox.vue";
import GenreTagInput from "@/components/genres/GenreTagInput.vue";
import RatingSelect from "@/components/books/RatingSelect.vue";
import CopyFields from "@/components/books/CopyFields.vue";

/**
 * One page, one book, the copy in hand.
 *
 * This replaces a seven-step wizard whose steps were separate forms swapped in
 * by the store, with no way back and nothing kept on a reload. The draft
 * lives here; `utils/newBookForm.js` owns what makes it valid and what it
 * sends, so the page is layout and wiring.
 */
const router = useRouter();
// Formats are needed here too, not just in `CopyFields`: validation and the
// payload both read which length a format carries.
const ConfigStore = useConfigStore();

const draft = ref(emptyDraft());
const errors = ref({});
// Errors appear once the user has tried to submit, then track every edit —
// rather than shouting "Enter a title" at an empty form on arrival.
const attempted = ref(false);
const isSaving = ref(false);
const submitError = ref("");

const formats = computed(() => ConfigStore.books.formats);

const revalidate = () => {
    if (attempted.value) {
        errors.value = validateDraft(draft.value, formats.value);
    }
};

onMounted(() => {
    ConfigStore.checkForFormats();
});

// --- Same-title check -------------------------------------------------------

// Title alone isn't book identity — Plath's *Ariel* and Rodó's are two books
// — so a match is a heads-up with a way out ("add a copy to that one"), not a
// gate. The wizard made it a whole step; here it's a note under the field.
const matches = ref([]);
let checkedTitle = "";

const checkTitle = async () => {
    const title = draft.value.title.trim();

    if (title === checkedTitle) return;

    checkedTitle = title;

    if (!title) {
        matches.value = [];
        return;
    }

    try {
        const res = await createOrGetBookByTitle(title);

        // A slower response for an earlier title must not overwrite a newer one.
        if (checkedTitle === title) {
            matches.value = res.data.matches ?? [];
        }
    } catch (error) {
        // Advisory only: a failed check shouldn't block creating the book.
        console.error("Error checking title:", error);
    }
};

// --- Authors ----------------------------------------------------------------

const addAuthor = () => {
    if (canAddAuthor(draft.value.authors)) {
        draft.value.authors.push(emptyAuthor());
    }
};

const removeAuthor = (index) => {
    draft.value.authors.splice(index, 1);
    revalidate();
};

const updateCopy = (copy) => {
    draft.value.copy = copy;
    revalidate();
};

// --- Submit -----------------------------------------------------------------

const submit = async () => {
    attempted.value = true;
    submitError.value = "";
    errors.value = validateDraft(draft.value, formats.value);

    if (Object.keys(errors.value).length || isSaving.value) return;

    isSaving.value = true;

    try {
        const res = await submitNewBook(toPayload(draft.value, formats.value));

        router.push({
            name: "books.show",
            params: { slug: res.data.book.slug },
        });
    } catch (error) {
        console.error("Error creating book:", error);
        submitError.value = submitErrorMessage(error);
        isSaving.value = false;
    }
};

const sectionHeading =
    "mb-3 border-b border-zinc-200 pb-1 text-base font-bold text-zinc-500";
const label = "mb-1 block text-sm text-zinc-500";
const fieldError = "mb-0 mt-1 text-sm text-red-600";
const select =
    "w-full rounded-md border border-zinc-300 bg-white px-2 py-2 capitalize focus:border-zinc-600 focus:outline-none";
const action = "underline hover:no-underline";
</script>

<template>
    <div class="max-w-2xl">
        <router-link
            :to="{ name: 'library.index' }"
            class="text-sm text-zinc-500 underline hover:no-underline"
        >
            &larr; Library
        </router-link>

        <h1 class="mb-1 mt-2">New book</h1>
        <p class="mb-8 text-zinc-500">
            The book, and the copy of it you have. Further copies and reads can
            be added from the book's page.
        </p>

        <form novalidate @submit.prevent="submit">
            <section class="mb-8">
                <h2 :class="sectionHeading">Book</h2>

                <div class="mb-5">
                    <label for="new-book-title" :class="label">Title</label>
                    <input
                        id="new-book-title"
                        v-model="draft.title"
                        type="text"
                        autocomplete="off"
                        @input="revalidate"
                        @change="checkTitle"
                    />
                    <p v-if="errors.title" :class="fieldError">
                        {{ errors.title }}
                    </p>

                    <div
                        v-if="matches.length"
                        class="mt-3 rounded-md border border-zinc-300 bg-zinc-50 px-3 py-2 text-sm"
                    >
                        <p class="mb-1 text-zinc-600">
                            Already in the library under this title:
                        </p>
                        <ul class="mb-1">
                            <li
                                v-for="match in matches"
                                :key="match.book_id"
                                class="flex flex-wrap items-baseline justify-between gap-x-4 py-0.5"
                            >
                                <span>
                                    <router-link
                                        :to="{
                                            name: 'books.show',
                                            params: { slug: match.slug },
                                        }"
                                        class="font-medium hover:underline"
                                        >{{ match.title }}</router-link
                                    >
                                    <span class="text-zinc-500">
                                        — {{ matchAuthorLine(match) }}</span
                                    >
                                </span>
                                <router-link
                                    :to="{
                                        name: 'books.add-version',
                                        params: { slug: match.slug },
                                    }"
                                    :class="action"
                                    >Add a copy to this one</router-link
                                >
                            </li>
                        </ul>
                        <p class="mb-0 text-zinc-500">
                            If this is a different book, carry on — it will be
                            filed under its author's name.
                        </p>
                    </div>
                </div>

                <fieldset class="mb-5">
                    <legend :class="label">Authors</legend>
                    <div
                        v-for="(author, index) in draft.authors"
                        :key="index"
                        class="mb-1 flex items-end gap-x-4"
                    >
                        <input
                            v-model="author.first_name"
                            type="text"
                            placeholder="First name"
                            :aria-label="`Author ${index + 1} first name`"
                            @input="revalidate"
                        />
                        <input
                            v-model="author.last_name"
                            type="text"
                            placeholder="Last name"
                            :aria-label="`Author ${index + 1} last name`"
                            @input="revalidate"
                        />
                        <button
                            v-if="draft.authors.length > 1"
                            type="button"
                            :class="[
                                action,
                                'shrink-0 pb-2 text-sm text-zinc-500',
                            ]"
                            @click="removeAuthor(index)"
                        >
                            Remove
                        </button>
                    </div>
                    <button
                        type="button"
                        class="mt-1 text-sm"
                        :class="
                            canAddAuthor(draft.authors)
                                ? action
                                : 'cursor-not-allowed text-zinc-400'
                        "
                        :disabled="!canAddAuthor(draft.authors)"
                        @click="addAuthor"
                    >
                        Add another author
                    </button>
                    <p v-if="errors.authors" :class="fieldError">
                        {{ errors.authors }}
                    </p>
                </fieldset>

                <div>
                    <p :class="label">Genres</p>
                    <GenreTagInput v-model="draft.genres" />
                </div>
            </section>

            <section class="mb-8">
                <h2 :class="sectionHeading">Your copy</h2>
                <CopyFields
                    :copy="draft.copy"
                    :errors="errors"
                    id-prefix="new-book"
                    @update:copy="updateCopy"
                />
            </section>

            <section class="mb-8">
                <h2 :class="sectionHeading">Reading</h2>

                <label class="flex cursor-pointer items-center gap-2">
                    <input
                        v-model="draft.read.has_read"
                        type="checkbox"
                        class="h-4 w-4 accent-slate-900"
                    />
                    I've read this copy
                </label>

                <div
                    v-if="draft.read.has_read"
                    class="mt-4 grid gap-x-6 gap-y-5 sm:grid-cols-2"
                >
                    <div>
                        <label for="new-book-date-read" :class="label"
                            >Finished (blank if unknown)</label
                        >
                        <input
                            id="new-book-date-read"
                            v-model="draft.read.date_read"
                            type="date"
                            class="w-full rounded-md border border-zinc-300 bg-white px-2 py-1.5 focus:border-zinc-600 focus:outline-none"
                        />
                    </div>
                    <div>
                        <label for="new-book-rating" :class="label"
                            >Rating</label
                        >
                        <RatingSelect
                            id="new-book-rating"
                            v-model="draft.read.rating"
                            :class="select"
                            clearable
                        />
                    </div>
                </div>
            </section>

            <AlertBox
                v-if="submitError"
                :message="submitError"
                alert-type="danger"
            />

            <div class="flex items-center gap-4">
                <button
                    type="submit"
                    class="btn btn-primary"
                    :disabled="isSaving"
                >
                    {{ isSaving ? "Saving…" : "Add book" }}
                </button>
                <router-link
                    :to="{ name: 'library.index' }"
                    :class="[action, 'text-sm text-zinc-500']"
                    >Cancel</router-link
                >
            </div>
        </form>
    </div>
</template>
