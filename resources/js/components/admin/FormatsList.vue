<script setup>
import { computed } from "vue";
import { useConfigStore } from "@/stores";

const configStore = useConfigStore();
await configStore.checkForFormats();

const formats = computed(() => configStore.books.formats);
</script>

<template>
    <ul v-if="formats?.length">
        <li v-for="format in formats" :key="format.id">
            {{ format.name }}
        </li>
    </ul>
        <p v-else>No formats found.</p>
</template>