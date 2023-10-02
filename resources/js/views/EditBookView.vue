<template>
  <div v-if="!currentBook">
    <div class="bg-slate-400 p-4 w-1/2 mb-4"></div>
    <div class="bg-slate-400 p-4 w-1/2 mb-4"></div>
  </div>
  <div v-else>
    <h1>Edit [{{ currentBook.title }}]</h1>
    <BookCreateEditForm :current-book="currentBook" class="w-1/2" />
  </div>
</template>

<script>
import { useBooksStore } from "@/stores";

import { getOneBookFromSlug } from "@/api/BookController";

import BookCreateEditForm from "@/components/books/forms/BookCreateEditForm.vue";

export default {
  name: "EditBookView",
  components: {
    BookCreateEditForm,
  },
  setup() {
    const BooksStore = useBooksStore();

    return {
      BooksStore,
    };
  },
  data() {
    return {
      currentSlug: this.$route.params.slug,
    };
  },
  computed: {
    currentBook() {
      return this.BooksStore.allBooks.find(
        (b) => b.slug === this.$route.params.slug
      );
    },
  },
  async mounted() {
    if (!this.currentBook) {
      try {
        const book = await getOneBookFromSlug(this.currentSlug);
        this.BooksStore.addBook(book.data);
      } catch (error) {
        console.log(error);
      }
    }
  },
};
</script>
