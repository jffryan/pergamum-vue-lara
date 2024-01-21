<template>
  <div v-if="BacklogStore.backlog">
    <component :is="innerComponent"></component>
  </div>
</template>

<script>
import { useBacklogStore } from "@/stores";

import BacklogDashboard from "@/components/backlog/BacklogDashboard.vue";
import BacklogIndex from "@/components/backlog/BacklogIndex.vue";

export default {
  name: "BacklogHome",
  components: {
    BacklogDashboard,
    BacklogIndex,
  },
  props: {
    innerComponent: {
      type: String,
      required: true,
    },
  },
  setup() {
    const BacklogStore = useBacklogStore();

    return {
      BacklogStore,
    };
  },
  mounted() {
    this.BacklogStore.fetchAndSetBacklog();
  },
};
</script>
