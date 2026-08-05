<script setup>
import { computed } from "vue";
import adminRoutes from "@/router/admin-routes";

// Derived from the route table rather than hand-written, which kills the
// "added a route, forgot the link" failure mode: an action is discoverable the
// moment it declares `meta.adminMenu`, and there is no third place to update.
const actions = computed(() =>
    adminRoutes
        .filter((route) => route.meta?.adminMenu)
        .map((route) => ({ name: route.name, ...route.meta.adminMenu })),
);
</script>

<template>
    <main>
        <h1 class="text-2xl font-bold mb-4">Admin Home</h1>
        <ul v-if="actions.length">
            <li v-for="action in actions" :key="action.name" class="mb-4">
                <router-link
                    :to="{ name: action.name }"
                    class="underline font-bold"
                >
                    {{ action.title }}
                </router-link>
                <p v-if="action.description" class="text-sm text-zinc-600">
                    {{ action.description }}
                </p>
            </li>
        </ul>
        <p v-else>No admin actions are registered.</p>
    </main>
</template>
