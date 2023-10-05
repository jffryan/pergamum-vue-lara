import { defineStore } from "pinia";

const useConfirmationModalStore = defineStore("ConfirmationModalStore", {
  state: () => ({
    confirmationModalActive: false,
    confirmationModalComponent: null,
    confirmationModalData: {},
  }),
  actions: {
    // ---------------------
    // Show confirmation modal
    // ---------------------
    showConfirmationModal(component, data) {
      this.confirmationModalActive = true;
      this.confirmationModalComponent = component;
      this.confirmationModalData = data;
    },
    // ---------------------
    // Hide confirmation modal
    // ---------------------
    hideConfirmationModal() {
      this.confirmationModalActive = false;
      this.confirmationModalComponent = null;
      this.confirmationModalData = {};
    },
  },
});

export default useConfirmationModalStore;
