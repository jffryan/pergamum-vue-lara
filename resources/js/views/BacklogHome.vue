<template>
  <component :is="innerComponent"></component>
</template>

<script>
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
};
</script>
