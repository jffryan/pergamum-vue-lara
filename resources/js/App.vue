<template>
  <div class="body-grid">
    <SidebarNav />
    <RouterView class="py-10 px-20 w-full body-container" />
  </div>
</template>

<script>
import { RouterView } from "vue-router";
import SidebarNav from "@/components/navs/SidebarNav.vue";

export default {
  name: "App",
  components: {
    SidebarNav,
  },
};
</script>

<style scoped>
.body-grid {
  display: grid;
  grid-template-columns: 1fr 5fr;
  min-height: 100vh;
}

.body-container {
  max-width: 1440px;
  margin: 0 auto;
}
</style>
