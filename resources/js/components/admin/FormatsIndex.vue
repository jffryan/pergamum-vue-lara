<script setup>
import FormatsList from "@/components/admin/FormatsList.vue";
import CreateFormat from "@/components/admin/CreateFormat.vue";
</script>

<template>
    <h2 class="text-xl mb-4">Formats</h2>
    <Suspense>
        <template #default>
            <FormatsList />
        </template>
        <template #fallback>
            <div>Loading formats...</div>
        </template>
    </Suspense>
    <CreateFormat />
</template>