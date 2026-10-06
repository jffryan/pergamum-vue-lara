<template>
    <select
        :value="modelValue ?? ''"
        @change="
            $emit(
                'update:modelValue',
                $event.target.value === '' ? null : Number($event.target.value),
            )
        "
    >
        <option value="" :disabled="!clearable && modelValue != null">
            No rating
        </option>
        <option v-for="rating in RATINGS" :key="rating" :value="rating">
            {{ rating }} / 5
        </option>
    </select>
</template>

<script setup>
import { RATINGS } from "@/utils/newBookForm";

/**
 * The one rating picker: every step `App\Rules\Rating` accepts. An unrated
 * read shows "No rating", but once a rating is picked it can only be changed,
 * not removed — unless `clearable`, which the new-book form uses because its
 * read is still an unsaved draft. `id` and classes fall through to the
 * `<select>`.
 */
defineProps({
    modelValue: {
        type: [Number, String],
        default: null,
    },
    clearable: {
        type: Boolean,
        default: false,
    },
});
defineEmits(["update:modelValue"]);
</script>
