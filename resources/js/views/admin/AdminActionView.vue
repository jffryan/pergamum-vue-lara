<script setup>
import {computed} from "vue";
import { useRoute } from "vue-router";
import FormatsIndex from "@/components/admin/FormatsIndex.vue";

const route = useRoute();

const components = {
    FormatsIndex,
};

const currentComponent = computed(() => components[route.meta.component]);
</script>

<template>
    <main>
        <h1 class="text-2xl font-bold mb-4">Admin Actions</h1>
        <component :is="currentComponent" />
    </main>
</template>
