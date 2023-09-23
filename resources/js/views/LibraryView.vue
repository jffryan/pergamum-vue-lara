<template>
  <div>
    <h1>Library</h1>
    <BookshelfTable :books="books" />
  </div>
</template>

<script>
import { getAllBooks } from "@/api/BookController";

import { useBooksStore } from "@/stores";

import BookshelfTable from "@/components/books/table/BookshelfTable.vue";

export default {
  name: "LibraryView",
  components: {
    BookshelfTable,
  },
  setup() {
    const BooksStore = useBooksStore();

    return {
      BooksStore,
    };
  },
  computed: {
    books() {
      return this.BooksStore.allBooks;
    },
  },
  async mounted() {
    // I just know this is going to cause a bug later XD
    if (this.BooksStore.allBooks.length < 2) {
      const books = await getAllBooks();
      this.BooksStore.setAllBooks(books.data);
    }
  },
};
</script>
