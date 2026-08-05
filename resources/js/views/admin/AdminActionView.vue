<script setup>
import { computed, defineAsyncComponent } from "vue";
import { useRoute } from "vue-router";

const route = useRoute();

// `defineAsyncComponent` rather than static imports: every entry in this map
// used to be bundled into the admin route chunk regardless of which action the
// user opened. Each action now loads its own chunk.
const components = {
    FormatsIndex: defineAsyncComponent(
        () => import("@/components/admin/FormatsIndex.vue"),
    ),
    GenresIndex: defineAsyncComponent(
        () => import("@/components/admin/genres/GenresIndex.vue"),
    ),
};

const currentComponent = computed(() => components[route.meta.component]);
</script>

<template>
    <main>
        <h1 class="text-2xl font-bold mb-4">Admin Actions</h1>
        <component :is="currentComponent" />
    </main>
</template>
