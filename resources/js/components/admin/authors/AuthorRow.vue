<script setup>
import { computed, nextTick, ref } from "vue";
import { useAuthorsStore } from "@/stores";
import { authorName } from "@/utils/authorList";

const props = defineProps({
    author: {
        type: Object,
        required: true,
    },
    selected: {
        type: Boolean,
        default: false,
    },
});

const emit = defineEmits(["toggle-select", "merged"]);

const authorsStore = useAuthorsStore();

const editing = ref(false);
const draft = ref({ first_name: "", last_name: "" });
const firstInput = ref(null);

// The other author off a 409, kept so the rename can hand straight off to a
// merge — which is why the API answers a taken name with the author rather
// than a validation message.
const conflict = ref(null);

const error = ref(null);
const busy = ref(false);

const name = computed(() => authorName(props.author));

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
    draft.value = {
        first_name: props.author.first_name ?? "",
        last_name: props.author.last_name ?? "",
    };
    editing.value = true;
    await nextTick();
    firstInput.value?.focus();
}

function cancelRename() {
    editing.value = false;
    conflict.value = null;
    error.value = null;
}

async function submitRename() {
    const names = {
        first_name: draft.value.first_name.trim(),
        last_name: draft.value.last_name.trim(),
    };

    if (!names.first_name && !names.last_name) {
        error.value = "An author needs a first name or a last name.";
        return;
    }

    if (
        names.first_name === (props.author.first_name ?? "") &&
        names.last_name === (props.author.last_name ?? "")
    ) {
        cancelRename();
        return;
    }

    busy.value = true;
    error.value = null;
    conflict.value = null;

    try {
        await authorsStore.renameAuthor(props.author.author_id, names);
        editing.value = false;
    } catch (e) {
        if (e.response?.data?.reason_code === "author_name_taken") {
            conflict.value = e.response.data.conflict;
        } else {
            error.value = readError(e);
        }
    } finally {
        busy.value = false;
    }
}

// One click from "that name is taken" to "then they're the same person".
async function mergeIntoConflict() {
    busy.value = true;
    error.value = null;

    try {
        await authorsStore.mergeAuthors(conflict.value.author_id, [
            props.author.author_id,
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

const conflictPrompt = computed(() => {
    if (!conflict.value) {
        return "";
    }

    return `"${authorName(conflict.value)}" already exists (${conflict.value.books_count} book(s)). Merge "${name.value}" into them? Their ${props.author.books_count} book(s) will be credited to "${authorName(conflict.value)}", and "${name.value}" will be deleted. This cannot be undone.`;
});
</script>

<template>
    <tr class="border-b align-top">
        <td class="py-1">
            <input
                type="checkbox"
                :checked="selected"
                :aria-label="`Select ${name} for merge`"
                @change="$emit('toggle-select')"
            />
        </td>
        <td class="py-1">
            <form
                v-if="editing"
                class="flex gap-2 flex-wrap"
                @submit.prevent="submitRename"
                @keyup.esc="cancelRename"
            >
                <label
                    :for="`author-first-${author.author_id}`"
                    class="sr-only"
                >
                    First name
                </label>
                <input
                    :id="`author-first-${author.author_id}`"
                    ref="firstInput"
                    v-model="draft.first_name"
                    type="text"
                    placeholder="First name"
                    class="border px-2 py-1 bg-transparent"
                />
                <label :for="`author-last-${author.author_id}`" class="sr-only">
                    Last name
                </label>
                <input
                    :id="`author-last-${author.author_id}`"
                    v-model="draft.last_name"
                    type="text"
                    placeholder="Last name"
                    class="border px-2 py-1 bg-transparent"
                />
                <button type="submit" class="btn btn-primary" :disabled="busy">
                    Save
                </button>
                <button type="button" class="underline" @click="cancelRename">
                    Cancel
                </button>
            </form>
            <template v-else>
                <router-link
                    :to="{
                        name: 'authors.show',
                        params: { slug: author.slug },
                    }"
                    class="hover:underline"
                >
                    {{ name }}
                </router-link>
                <span class="block text-xs text-zinc-500">
                    /authors/{{ author.slug }}
                </span>
            </template>
        </td>
        <td class="py-1">{{ author.books_count }}</td>
        <td class="py-1">
            <button
                v-if="!editing"
                type="button"
                class="underline"
                @click="startRename"
            >
                Rename
            </button>
        </td>
    </tr>
    <tr v-if="conflict || error">
        <td colspan="4" class="pb-2">
            <div
                v-if="conflict"
                class="border border-amber-400 bg-amber-50 text-zinc-900 p-3 text-sm"
            >
                <p>{{ conflictPrompt }}</p>
                <div class="flex gap-2 mt-2">
                    <button
                        type="button"
                        class="btn btn-primary"
                        :disabled="busy"
                        @click="mergeIntoConflict"
                    >
                        Merge into them
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

            <p v-if="error" class="text-red-600 mt-1 text-sm">{{ error }}</p>
        </td>
    </tr>
</template>
