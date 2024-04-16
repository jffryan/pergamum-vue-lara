<template>
  <form
    @submit.prevent="submitVersion"
    class="p-4 mb-4 bg-white border rounded-md border-zinc-400 shadow-md"
  >
    <div class="mb-4">
      <label for="title" class="block mb-2 font-bold text-zinc-600 mr-6"
        >Add Version</label
      >
      <label for="format" class="block mb-2 font-bold text-zinc-600 mr-6"
        >Format</label
      >
      <select
        v-model="version.format"
        class="mb-4 bg-zinc-100 text-zinc-700 capitalize border rounded p-2 focus:border-zinc-500 focus:outline-none"
      >
        <option value="" class="text-zinc-400" disabled>Select a format</option>
        <option
          v-for="format in formats"
          :key="format.format_id"
          :value="format"
          class="text-zinc-700 capitalize"
        >
          {{ format.name }}
        </option>
      </select>
      <p v-if="!isValid.format" class="p-2 text-red-300">Format is required.</p>
      <div class="flex justify-between gap-x-4 mb-4">
        <!-- Page count field -->
        <div class="w-full">
          <label
            for="page_count"
            class="block mb-2 font-bold text-zinc-600 mr-6"
            >Page Count</label
          >
          <input
            type="text"
            id="page_count"
            name="page_count"
            placeholder="Page Count"
            v-model="version.page_count"
            @input="
              version.page_count = $event.target.value.replace(/[^0-9]/g, '')
            "
          />
          <p v-if="!isValid.page_count" class="p-2 text-red-300">
            Enter a valid page count.
          </p>
        </div>
        <!-- Audio runtime field -->
        <div v-if="version.format?.format_id === 2" class="mb-4 w-full">
          <label
            for="audio_runtime"
            class="block mb-2 font-bold text-zinc-600 mr-6"
            >Audio Runtime</label
          >
          <input
            type="text"
            id="audio_runtime"
            name="audio_runtime"
            placeholder="Audio Runtime"
            v-model="version.audio_runtime"
            @input="
              version.audio_runtime = $event.target.value.replace(/[^0-9]/g, '')
            "
          />
          <p v-if="!isValid.audio_runtime" class="p-2 text-red-300">
            Enter a valid audio runtime.
          </p>
        </div>
      </div>
      <label for="nickname" class="block mb-2 font-bold text-zinc-600 mr-6"
        >Nickname</label
      >
      <input
        v-model="version.nickname"
        type="text"
        class="input"
        placeholder="Nickname"
      />
    </div>
    <label for="is_read" class="block mb-2 font-bold text-zinc-600"
      >I have read this version</label
    >
    <div class="mt-auto">
      <label class="relative inline-flex items-center cursor-pointer">
        <input
          type="checkbox"
          v-model="version.is_read"
          class="sr-only peer"
          :true-value="1"
          :false-value="0"
        />
        <div
          class="w-11 h-6 bg-slate-400 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-gray-700"
        ></div>
        <span class="ml-3 font-medium">{{
          version.is_read ? "Yes" : "No"
        }}</span>
      </label>
    </div>
    <!-- End version -->
    <div class="flex justify-end">
      <button class="btn btn-primary" type="submit">Submit</button>
    </div>
  </form>
</template>

<script>
import { useNewBookStore } from "@/stores";

export default {
  name: "NewVersionsInput",
  setup() {
    const NewBookStore = useNewBookStore();

    return {
      NewBookStore,
    };
  },
  data() {
    return {
      version: {
        nickname: "",
        format: null,
        page_count: null,
        audio_runtime: null,
        is_read: false,
      },
      isValid: {
        format: true,
        page_count: true,
        audio_runtime: true,
      },
      formats: [
        { format_id: 1, name: "paper" },
        { format_id: 2, name: "audio" },
        { format_id: 3, name: "ebook" },
        { format_id: 4, name: "pirated" },
        { format_id: 5, name: "borrowed" },
      ],
    };
  },
  methods: {
    validateVersion() {
      this.isValid.format = !!this.version.format;
      this.isValid.page_count = !!this.version.page_count;
      if (this.version.format === 2) {
        this.isValid.audio_runtime = !!this.version.audio_runtime;
      }
      return Object.values(this.isValid).every((isValid) => isValid);
    },
    submitVersion() {
      const isValid = this.validateVersion();
      if (!isValid) return;
      this.NewBookStore.addVersionToNewBook(this.version);
    },
  },
};
</script>
