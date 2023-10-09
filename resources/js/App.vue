<template>
  <HeaderNav />
  <div class="relative max-w-8xl mx-auto px-4 sm:px-6 md:px-8">
    <SidebarNav
      class="fixed z-20 inset-0 top-16 left-[max(0px,calc(50%-45rem))] right-auto w-[20rem] py-6 pr-8 overflow-y-auto"
    />
    <div>
      <RouterView class="py-6 pl-[20rem] w-full" />
    </div>
    <div
      v-if="ConfirmationModalStore.confirmationModalActive"
      class="fixed inset-0 bg-slate-950 bg-opacity-60 overflow-y-auto h-full w-full"
      @click.self="ConfirmationModalStore.hideConfirmationModal()"
    >
      <ConfirmationModal
        class="absolute top-1/3 left-1/2 transform -translate-x-1/2 -translate-y-1/2 max-w-xl"
        key="confirmation-modal"
      />
    </div>
  </div>
</template>

<script>
import { useConfirmationModalStore } from "@/stores";

import ConfirmationModal from "@/components/modals/confirmation/ConfirmationModal.vue";
import HeaderNav from "@/components/navs/HeaderNav.vue";
import SidebarNav from "@/components/navs/SidebarNav.vue";

export default {
  name: "App",
  components: {
    ConfirmationModal,
    HeaderNav,
    SidebarNav,
  },
  setup() {
    const ConfirmationModalStore = useConfirmationModalStore();

    return {
      ConfirmationModalStore,
    };
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
