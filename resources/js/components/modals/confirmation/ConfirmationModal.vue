<template>
  <div>
    <component :is="getCurrentComponent" />
  </div>
</template>

<script>
import { ref, computed } from "vue";

import ConfirmDeleteBook from "@/components/modals/confirmation/ConfirmDeleteBook.vue";
import ConfirmBookDeleted from "@/components/modals/confirmation/ConfirmBookDeleted.vue";

import { useConfirmationModalStore } from "@/stores";

export default {
  name: "ConfirmationModal",
  components: {
    ConfirmDeleteBook,
    ConfirmBookDeleted,
  },
  setup() {
    const ConfirmationModalStore = useConfirmationModalStore();
    const componentsMap = ref({
      confirmDeleteBook: "ConfirmDeleteBook",
      confirmBookDeleted: "ConfirmBookDeleted",
    });

    const getCurrentComponent = computed(() => {
      return (
        componentsMap.value[
          ConfirmationModalStore.confirmationModalComponent
        ] || null
      );
    });

    return { getCurrentComponent, ConfirmationModalStore };
  },
  beforeUnmount() {
    this.ConfirmationModalStore.hideConfirmationModal();
  },
};
</script>
