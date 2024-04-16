<template>
  <form
    @submit.prevent="submitBacklogItem"
    class="p-4 mb-4 bg-white border rounded-md border-zinc-400 shadow-md"
  >
    <div>
      <label for="request" class="block mb-2 font-bold text-zinc-600">
        Would you like to add this book to your backlog?
      </label>
      <div class="mt-auto">
        <label class="relative inline-flex items-center cursor-pointer">
          <input
            type="checkbox"
            v-model="is_backlog"
            class="sr-only peer"
            :true-value="1"
            :false-value="0"
          />
          <div
            class="w-11 h-6 bg-slate-400 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-gray-700"
          ></div>
          <span class="ml-3 font-medium">{{ is_backlog ? "Yes" : "No" }}</span>
        </label>
      </div>
    </div>
    <div class="flex justify-end">
      <button class="btn btn-primary" type="submit">Submit</button>
    </div>
  </form>
</template>

<script>
import { useNewBookStore } from "@/stores";

export default {
  name: "NewBacklogItemInput",
  setup() {
    const NewBookStore = useNewBookStore();

    return {
      NewBookStore,
    };
  },
  data() {
    return {
      is_backlog: 0,
    };
  },
  methods: {
    submitBacklogItem() {
      this.NewBookStore.setBacklogItemToNewBook(this.is_backlog);
    },
  },
};
</script>
