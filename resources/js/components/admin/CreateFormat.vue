<script setup>
import { ref } from "vue";
import { useConfigStore } from "@/stores";

const configStore = useConfigStore();

const name = ref("");
const expectsPageCount = ref(true);
const expectsAudioRuntime = ref(false);
const error = ref(null);
const success = ref(false);

async function submit() {
    error.value = null;
    success.value = false;
    try {
        await configStore.createFormat(name.value.trim(), {
            expects_page_count: expectsPageCount.value,
            expects_audio_runtime: expectsAudioRuntime.value,
        });
        name.value = "";
        expectsPageCount.value = true;
        expectsAudioRuntime.value = false;
        success.value = true;
    } catch (e) {
        error.value = e.response?.data?.message ?? "An error occurred.";
    }
}
</script>

<template>
    <form @submit.prevent="submit" class="mt-4">
        <div class="flex gap-2">
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
        </div>
        <!-- What a format is measured in. The book forms read these to decide
             which length inputs to render, so getting them wrong here hides a
             field from every version of this format. -->
        <fieldset class="flex gap-4 mt-2 text-sm text-zinc-600">
            <legend class="sr-only">Length fields this format carries</legend>
            <label class="flex items-center gap-1">
                <input v-model="expectsPageCount" type="checkbox" />
                Has a page count
            </label>
            <label class="flex items-center gap-1">
                <input v-model="expectsAudioRuntime" type="checkbox" />
                Has an audio runtime
            </label>
        </fieldset>
    </form>
    <p v-if="success" class="text-green-600 mt-1">Format created.</p>
    <p v-if="error" class="text-red-600 mt-1">{{ error }}</p>
</template>
