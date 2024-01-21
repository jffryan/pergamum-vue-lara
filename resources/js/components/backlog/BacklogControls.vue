<template>
  <div>
    <div class="bg-slate-900 text-slate-200 rounded-t-md p-2">
      <h4 class="mb-0">Filters</h4>
    </div>
    <div class="p-2 flex items-center">
      <div class="flex items-center mr-4">
        <input
          id="is_complete"
          type="radio"
          :value="false"
          name="backlog-status"
          v-model="BacklogStore.isnot_complete"
          class="w-4 h-4 border-gray-300 focus:ring-blue-500 dark:focus:ring-blue-600"
        />
        <label for="is_complete" class="ms-2 text-sm font-medium"
          >Completed</label
        >
      </div>
      <div class="flex items-center mr-4">
        <input
          checked
          id="isnot_complete"
          type="radio"
          :value="true"
          name="backlog-status"
          v-model="BacklogStore.isnot_complete"
          class="w-4 h-4 border-gray-300 focus:ring-blue-500 dark:focus:ring-blue-600"
        />
        <label for="isnot_complete" class="ms-2 text-sm font-medium"
          >Current backlog</label
        >
      </div>
      <div>
        <button
          @click="BacklogStore.updateBacklogOrdinals"
          class="btn btn-primary"
        >
          Save changes
        </button>
      </div>
    </div>
  </div>
</template>

<script>
import { useBacklogStore } from "@/stores";

export default {
  name: "BacklogControls",
  setup() {
    const BacklogStore = useBacklogStore();

    return {
      BacklogStore,
    };
  },
};
</script>
