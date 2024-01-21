<template>
  <section class="flex gap-x-8">
    <div
      class="p-4 border-slate-900 border rounded-md bg-slate-200 text-center"
    >
      <h2 class="font-bold">Completed</h2>
      <p class="text-center text-3xl">{{ completedCount }}</p>
    </div>
    <div
      class="p-4 border-slate-900 border rounded-md bg-slate-200 text-center"
    >
      <h2 class="font-bold">Remaining</h2>
      <p class="text-center text-3xl">{{ incompleteCount }}</p>
    </div>
  </section>
</template>

<script>
import { useBacklogStore } from "@/stores";

export default {
  name: "BacklogCounter",
  setup() {
    const BacklogStore = useBacklogStore();

    return {
      BacklogStore,
    };
  },
  computed: {
    completedCount() {
      // if is_completed is true, add 1 to the count
      return this.BacklogStore.backlog.completedItems.length;
    },
    incompleteCount() {
      return this.BacklogStore.backlog.incompleteItems.length;
    },
  },
};
</script>
