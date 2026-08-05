<script setup>
import { computed, nextTick, ref } from "vue";
import { useGenreStore } from "@/stores";
import ConfirmAction from "@/components/globals/ConfirmAction.vue";

const props = defineProps({
    genre: {
        type: Object,
        required: true,
    },
    selected: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(["toggle-select", "merged"]);

const genreStore = useGenreStore();

const editing = ref(false);
const draftName = ref("");
const nameInput = ref(null);

// The colliding genre off a 409, kept so the rename can hand straight off to a
// merge. This is why the API answers a name conflict with the genre rather
// than a validation message: without `conflict.genre_id` the SPA would have to
// go looking for it before it could offer this.
const conflict = ref(null);

const confirmingDelete = ref(false);
const deleteAcknowledged = ref(false);
const serverBooksCount = ref(0);

const error = ref(null);
const busy = ref(false);

function readError(e) {
    return (
        e.response?.data?.reason ??
        e.response?.data?.message ??
        "Something went wrong."
    );
}

async function startRename() {
    error.value = null;
    conflict.value = null;
    draftName.value = props.genre.name;
    editing.value = true;
    await nextTick();
    nameInput.value?.focus();
}

function cancelRename() {
    editing.value = false;
    conflict.value = null;
    error.value = null;
}

async function submitRename() {
    const name = draftName.value.trim();

    if (!name || name === props.genre.name) {
        cancelRename();
        return;
    }

    busy.value = true;
    error.value = null;
    conflict.value = null;

    try {
        await genreStore.renameGenre(props.genre.genre_id, name);
        editing.value = false;
    } catch (e) {
        if (e.response?.data?.reason_code === "genre_name_taken") {
            conflict.value = e.response.data.conflict;
        } else {
            error.value = readError(e);
        }
    } finally {
        busy.value = false;
    }
}

// One click from "that name is taken" to "then fold this one into it".
async function mergeIntoConflict() {
    busy.value = true;
    error.value = null;

    try {
        await genreStore.mergeGenres(conflict.value.genre_id, [
            props.genre.genre_id,
        ]);
        conflict.value = null;
        editing.value = false;
        emit("merged");
    } catch (e) {
        error.value = readError(e);
    } finally {
        busy.value = false;
    }
}

function startDelete() {
    error.value = null;
    deleteAcknowledged.value = false;
    confirmingDelete.value = true;
}

function cancelDelete() {
    confirmingDelete.value = false;
    deleteAcknowledged.value = false;
}

// Two-step on purpose. The first DELETE goes without `force`, so the server
// decides whether books are attached and reports the authoritative count; only
// then does the confirm escalate to the acknowledged wording and re-send with
// `force`. A genre nothing is tagged with is gone on the first click.
async function confirmDelete() {
    busy.value = true;
    error.value = null;

    try {
        await genreStore.deleteGenre(props.genre.genre_id, {
            force: deleteAcknowledged.value,
        });
        confirmingDelete.value = false;
    } catch (e) {
        if (e.response?.data?.reason_code === "genre_in_use") {
            serverBooksCount.value = e.response.data.books_count;
            deleteAcknowledged.value = true;
        } else {
            error.value = readError(e);
        }
    } finally {
        busy.value = false;
    }
}

const deleteImpact = computed(() => {
    if (deleteAcknowledged.value) {
        return `This genre is on ${serverBooksCount.value} book(s). Deleting it strips the tag from every one of them, and it cannot be undone.`;
    }

    if (props.genre.books_count > 0) {
        return `This genre is on ${props.genre.books_count} book(s).`;
    }

    return "No books are tagged with this genre.";
});

const deleteConfirmLabel = computed(() =>
    deleteAcknowledged.value
        ? `Yes, remove it from ${serverBooksCount.value} book(s)`
        : "Delete genre",
);

const conflictPrompt = computed(() => {
    if (!conflict.value) {
        return "";
    }

    return `A genre named "${conflict.value.name}" already exists (${conflict.value.books_count} book(s)). Merge "${props.genre.name}" into it?`;
});
</script>

<template>
    <tr class="border-b">
        <td class="py-1">
            <input
                type="checkbox"
                :checked="selected"
                :aria-label="`Select ${genre.name} for merge`"
                @change="$emit('toggle-select')"
            />
        </td>
        <td class="py-1">
            <form
                v-if="editing"
                class="flex gap-2"
                @submit.prevent="submitRename"
            >
                <label :for="`genre-name-${genre.genre_id}`" class="sr-only">
                    Genre name
                </label>
                <input
                    :id="`genre-name-${genre.genre_id}`"
                    ref="nameInput"
                    v-model="draftName"
                    type="text"
                    class="border px-2 py-1 bg-transparent"
                    @keyup.esc="cancelRename"
                />
                <button type="submit" class="btn btn-primary" :disabled="busy">
                    Save
                </button>
                <button type="button" class="underline" @click="cancelRename">
                    Cancel
                </button>
            </form>
            <button
                v-else
                type="button"
                class="capitalize hover:underline"
                @click="startRename"
            >
                {{ genre.name }}
            </button>
        </td>
        <td class="py-1">{{ genre.books_count }}</td>
        <td class="py-1">
            <button
                type="button"
                class="underline text-red-700"
                :disabled="busy"
                @click="startDelete"
            >
                Delete
            </button>
        </td>
    </tr>
    <tr v-if="conflict || confirmingDelete || error">
        <td colspan="4" class="pb-2">
            <div
                v-if="conflict"
                class="border border-amber-400 bg-amber-50 p-3 text-sm"
            >
                <p>{{ conflictPrompt }}</p>
                <div class="flex gap-2 mt-2">
                    <button
                        type="button"
                        class="btn btn-primary"
                        :disabled="busy"
                        @click="mergeIntoConflict"
                    >
                        Merge into it
                    </button>
                    <button
                        type="button"
                        class="underline"
                        @click="conflict = null"
                    >
                        Keep editing
                    </button>
                </div>
            </div>

            <ConfirmAction
                v-if="confirmingDelete"
                :title="`Delete &quot;${genre.name}&quot;?`"
                :impact="deleteImpact"
                :confirm-label="deleteConfirmLabel"
                :busy="busy"
                @confirm="confirmDelete"
                @cancel="cancelDelete"
            />

            <p v-if="error" class="text-red-600 mt-1 text-sm">{{ error }}</p>
        </td>
    </tr>
</template>
