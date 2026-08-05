<script setup>
/**
 * The shared destructive-action confirm.
 *
 * Lives in `globals/` rather than `admin/` because book delete and the
 * eventual author merge want the same component; genre delete and genre merge
 * are its first two consumers.
 *
 * `impact` is the point of it — a confirm that only says "are you sure?" is
 * noise. Callers pass the concrete consequence ("this will remove the genre
 * from 14 books") so the decision is made with the number in view.
 */
defineProps({
    title: {
        type: String,
        required: true,
    },
    impact: {
        type: String,
        default: "",
    },
    confirmLabel: {
        type: String,
        default: "Confirm",
    },
    busy: {
        type: Boolean,
        default: false,
    },
});

defineEmits(["confirm", "cancel"]);
</script>

<template>
    <div class="border border-red-400 bg-red-50 text-red-900 p-3 mt-2 text-sm">
        <p class="font-bold">{{ title }}</p>
        <p v-if="impact" class="mt-1">{{ impact }}</p>
        <div class="flex gap-2 mt-2 items-center">
            <button
                type="button"
                class="btn btn-danger"
                :disabled="busy"
                @click="$emit('confirm')"
            >
                {{ confirmLabel }}
            </button>
            <button type="button" class="underline" @click="$emit('cancel')">
                Cancel
            </button>
        </div>
    </div>
</template>
