<script setup>
import { calculateRuntime } from "@/services/BookServices";

defineProps({
    version: {
        type: Object,
        required: true,
    },
});
</script>

<template>
    <div class="grid grid-cols-12">
        <div class="col-span-3 p-2">
            {{ version.format.name }}
        </div>
        <div class="col-span-3 p-2">
            {{ version.page_count }}
        </div>
        <div v-if="version.audio_runtime" class="col-span-3 p-2">
            {{ calculateRuntime(version.audio_runtime) }}
        </div>
        <div v-else class="col-span-3 p-2"></div>
        <div class="col-span-3 p-2">
            {{ version.nickname }}
        </div>
    </div>
</template>
