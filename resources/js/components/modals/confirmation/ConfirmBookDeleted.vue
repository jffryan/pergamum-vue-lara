<template>
  <div>
    <div
      class="p-4 rounded-t-md bg-slate-900 text-slate-200 flex justify-between"
    >
      <h3 class="m-0" id="exampleModalLabel">Book Deleted</h3>
      <button
        type="button"
        class="text-white ml-auto"
        aria-label="Close"
        @click="dismissModal"
      >
        <CloseIcon class="stroke-white fill-white" />
      </button>
    </div>
    <div class="p-4 rounded-b-md bg-slate-200">
      <div class="modal-body mb-8">
        <p>
          {{ message }}
        </p>
        <ul v-if="deletedAuthors" class="list-disc">
          <li v-for="author in deletedAuthors" :key="author" class="ml-4">
            {{ author }}
          </li>
        </ul>
      </div>
      <div class="modal-footer mb-4">
        <router-link
          class="btn btn-secondary mr-4"
          :to="{ name: 'library.index' }"
          @click="ConfirmationModalStore.hideConfirmationModal"
        >
          Return to library
        </router-link>
      </div>
    </div>
  </div>
</template>

<script>
import { useConfirmationModalStore } from "@/stores";
import CloseIcon from "@/components/globals/svgs/CloseIcon.vue";

export default {
  name: "ConfirmBookDeleted",
  components: {
    CloseIcon,
  },
  setup() {
    const ConfirmationModalStore = useConfirmationModalStore();
    return {
      ConfirmationModalStore,
    };
  },
  computed: {
    message() {
      if (this.ConfirmationModalStore.confirmationModalData) {
        return this.ConfirmationModalStore.confirmationModalData
          .confirmationMessage;
      }
      return "An error has occurred.";
    },
    deletedAuthors() {
      if (
        this.ConfirmationModalStore.confirmationModalData.deletedAuthors &&
        this.ConfirmationModalStore.confirmationModalData.deletedAuthors
          .length > 0
      ) {
        return this.ConfirmationModalStore.confirmationModalData.deletedAuthors;
      }
      return null;
    },
  },
};
</script>
