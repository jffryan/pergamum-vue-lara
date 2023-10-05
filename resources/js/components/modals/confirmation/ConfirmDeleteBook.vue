<template>
  <div>
    <div
      class="p-4 rounded-t-md bg-slate-900 text-slate-200 flex justify-between"
    >
      <h3 class="m-0" id="exampleModalLabel">Delete {{ bookTitle }}</h3>
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
          Are you sure you want to delete this book? This operation cannot be
          undone.
        </p>
      </div>
      <div class="modal-footer">
        <button
          type="button"
          class="btn btn-secondary mr-4"
          @click="dismissModal"
        >
          Cancel
        </button>
        <button type="button" class="btn btn-danger" @click="requestDeleteBook">
          Delete
        </button>
      </div>
    </div>
  </div>
</template>

<script>
import { deleteBook } from "@/api/BookController";

import { useConfirmationModalStore } from "@/stores";
import CloseIcon from "@/components/globals/svgs/CloseIcon.vue";

export default {
  name: "ConfirmDeleteBook",
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
    bookInfo() {
      return this.ConfirmationModalStore.confirmationModalData;
    },
    bookId() {
      if (this.bookInfo) {
        return this.bookInfo.book_id;
      }
      return 0;
    },
    bookTitle() {
      if (this.bookInfo) {
        return this.bookInfo.title;
      }
      return "Unknown";
    },
  },
  methods: {
    async requestDeleteBook() {
      try {
        const res = await deleteBook(this.bookId);
        // If the book has been deleted, we need to check if any authors have been deleted as a result
        if (res.data.deleted_authors && res.data.deleted_authors.length) {
          const deletedAuthors = res.data.deleted_authors;
          const confirmationMessage = `${this.bookTitle} has been deleted. The following authors no longer have any books associated and have also been deleted:`;
          this.ConfirmationModalStore.showConfirmationModal(
            "confirmBookDeleted",
            { confirmationMessage, deletedAuthors }
          );
        } else {
          // If no authors have been deleted, we can just show the confirmation message
          this.ConfirmationModalStore.showConfirmationModal(
            "confirmBookDeleted",
            { confirmationMessage: `${this.bookTitle} has been deleted.` }
          );
        }
      } catch (err) {
        // If there was an error deleting the book, we need to show an error message
        console.log(err);
        this.ConfirmationModalStore.showConfirmationModal(
          "confirmBookDeleted",
          {
            confirmationMessage:
              "There was an error deleting this book. Please try again later.",
          }
        );
      }
    },
    dismissModal() {
      this.ConfirmationModalStore.hideConfirmationModal();
    },
  },
};
</script>
