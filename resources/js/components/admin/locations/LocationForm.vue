<script setup>
import { ref } from "vue";

/**
 * The field set a location create and a location edit share: code, name,
 * kind, ordinal. The parent is deliberately not here — creation gets it from
 * where the form was opened (an "add child" under a row, or root), and
 * re-parenting is the separate Move action with its own eligibility rules.
 *
 * `kind` is a datalist, not a select: the vocabulary is open by design (a
 * 'box' or a 'lent out' pile costs nothing server-side), so the common three
 * are suggestions rather than a fence.
 */
const props = defineProps({
    initial: {
        type: Object,
        default: () => ({ code: "", name: "", kind: "shelf", ordinal: null }),
    },
    submitLabel: {
        type: String,
        required: true,
    },
    busy: {
        type: Boolean,
        default: false,
    },
    idPrefix: {
        type: String,
        required: true,
    },
});

const emit = defineEmits(["submit", "cancel"]);

const code = ref(props.initial.code ?? "");
const name = ref(props.initial.name ?? "");
const kind = ref(props.initial.kind ?? "shelf");
const ordinal = ref(props.initial.ordinal ?? null);

function submit() {
    emit("submit", {
        code: code.value.trim(),
        name: name.value.trim() || null,
        kind: kind.value.trim(),
        ordinal:
            ordinal.value === null || ordinal.value === ""
                ? null
                : Number(ordinal.value),
    });
}
</script>

<template>
    <form
        class="flex flex-wrap items-end gap-2 text-sm"
        @submit.prevent="submit"
    >
        <div>
            <label :for="`${idPrefix}-code`" class="block mb-1">Code</label>
            <input
                :id="`${idPrefix}-code`"
                v-model="code"
                type="text"
                placeholder="O1S5"
                required
                class="border px-2 py-1 bg-transparent w-28"
            />
        </div>
        <div>
            <label :for="`${idPrefix}-name`" class="block mb-1"
                >Name (optional)</label
            >
            <input
                :id="`${idPrefix}-name`"
                v-model="name"
                type="text"
                placeholder="Office — tall case"
                class="border px-2 py-1 bg-transparent w-48"
            />
        </div>
        <div>
            <label :for="`${idPrefix}-kind`" class="block mb-1">Kind</label>
            <input
                :id="`${idPrefix}-kind`"
                v-model="kind"
                type="text"
                required
                :list="`${idPrefix}-kinds`"
                class="border px-2 py-1 bg-transparent w-28"
            />
            <datalist :id="`${idPrefix}-kinds`">
                <option value="room" />
                <option value="bookcase" />
                <option value="shelf" />
                <option value="box" />
            </datalist>
        </div>
        <div>
            <label :for="`${idPrefix}-ordinal`" class="block mb-1"
                >Position</label
            >
            <input
                :id="`${idPrefix}-ordinal`"
                v-model="ordinal"
                type="number"
                min="0"
                class="border px-2 py-1 bg-transparent w-20"
            />
        </div>
        <button type="submit" class="btn btn-primary" :disabled="busy">
            {{ submitLabel }}
        </button>
        <button type="button" class="underline" @click="emit('cancel')">
            Cancel
        </button>
    </form>
</template>
