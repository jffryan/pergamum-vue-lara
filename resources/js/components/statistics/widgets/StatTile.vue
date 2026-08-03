<script>
import { formatValue } from "@/services/statistics/formatters";

/**
 * One big number and what it means. The label sits under the number rather
 * than in the shell's heading slot because that's the tile's whole shape.
 */
export default {
    name: "StatTile",
    props: {
        value: {
            type: [Number, String],
            default: null,
        },
        label: {
            type: String,
            required: true,
        },
        format: {
            type: String,
            default: "number",
        },
        suffix: {
            type: String,
            default: null,
        },
    },
    computed: {
        display() {
            return formatValue(this.value, this.format);
        },
    },
};
</script>

<template>
    <div>
        <span class="block text-3xl sm:text-5xl font-bold mb-2 sm:mb-4">
            {{ display }}<span v-if="suffix" class="text-xl">{{ suffix }}</span>
        </span>
        <p class="text-sm sm:text-lg">{{ label }}</p>
    </div>
</template>
