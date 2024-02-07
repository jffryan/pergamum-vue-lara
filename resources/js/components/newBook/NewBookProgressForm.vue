<template>
  <div>
    <div v-if="title">
      <p class="mb-0"><span class="font-bold">Title: </span>{{ title }}</p>
    </div>
  </div>
</template>

<script>
import { useNewBookStore } from "@/stores";

export default {
  name: "NewBookProgressForm",
  setup() {
    const NewBookStore = useNewBookStore();

    return {
      NewBookStore,
    };
  },
  computed: {
    title() {
      return this.NewBookStore.currentBookData.book.title;
    },
  },
};
</script>
