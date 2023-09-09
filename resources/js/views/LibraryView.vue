<template>
  <h1>Library</h1>
</template>

<script>
import { getAllBooks } from "@/api/BookController.js";

export default {
  name: "LibraryView",

  async mounted() {
    const books = await getAllBooks();
    // @TODO: Long term, should think about where, how, and how many times we make server calls
    // to get the books, as well as where that gets stored in state/data.
    console.log(books.data);
  },
};
</script>

