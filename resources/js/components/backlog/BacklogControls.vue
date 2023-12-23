<template>
  <div>
    <div class="bg-slate-900 text-slate-200 rounded-t-md p-2">
      <h4 class="mb-0">Filters</h4>
    </div>
    <div
      class="flex items-center mb-4 bg-slate-200 text-black rounded-b-md p-2"
    >
      <div class="flex items-center mr-8">
        <input
          id="is_complete"
          type="checkbox"
          :checked="isComplete"
          @change="toggleFilter('is_complete')"
          class="w-4 h-4 bg-slate-100 border-slate-600 rounded focus:ring-blue-600 focus:ring-2"
        />
        <label for="is_complete" class="ms-2">Completed</label>
      </div>
      <div class="flex items-center">
        <input
          id="isnot_complete"
          type="checkbox"
          :checked="isNotComplete"
          @change="toggleFilter('isnot_complete')"
          class="w-4 h-4 bg-slate-100 border-slate-600 rounded focus:ring-blue-600 focus:ring-2"
        />
        <label for="isnot_complete" class="ms-2">Not Completed</label>
      </div>
    </div>
  </div>
</template>

<script>
import { computed } from "vue";

import { useBacklogStore } from "@/stores";

export default {
  name: "BacklogControls",
  setup() {
    const BacklogStore = useBacklogStore();
    const isComplete = computed(
      () => BacklogStore.filters.is_complete || false
    );
    const isNotComplete = computed(
      () => BacklogStore.filters.isnot_complete || false
    );

    function toggleFilter(filterName) {
      BacklogStore.toggleFilter(filterName);
    }

    return {
      isComplete,
      isNotComplete,
      toggleFilter,
    };
  },
};
</script>
