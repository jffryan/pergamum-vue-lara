<script setup>
import { ref } from "vue";
import { useConfigStore } from "@/stores";

const configStore = useConfigStore();

const name = ref("");
const error = ref(null);
const success = ref(false);

async function submit() {
    error.value = null;
    success.value = false;
    try {
        await configStore.createFormat(name.value.trim());
        name.value = "";
        success.value = true;
    } catch (e) {
        error.value = e.response?.data?.message ?? "An error occurred.";
    }
}
</script>

<template>
    <form @submit.prevent="submit" class="flex gap-2 mt-4">
        <label for="name" class="sr-only">Format Name</label>
        <input
            v-model="name"
            type="text"
            placeholder="Format name (e.g. Paperback)"
            required
            class="border px-2 py-1 bg-transparent"
        />
        <button type="submit" class="btn btn-primary shrink-0">
            Add Format
        </button>
    </form>
    <p v-if="success" class="text-green-600 mt-1">Format created.</p>
    <p v-if="error" class="text-red-600 mt-1">{{ error }}</p>
</template>
