<template>
  <div>
    <h1>{{ authorFullName }}</h1>
    <div v-if="currentAuthor.books">
      <BookshelfTable :books="currentAuthor.books" />
    </div>
  </div>
</template>

<script>
import { getAuthorBySlug } from "@/api/AuthorController";

import { useAuthorsStore } from "@/stores";

import BookshelfTable from "@/components/books/table/BookshelfTable.vue";

export default {
  name: "AuthorView",
  setup() {
    const AuthorsStore = useAuthorsStore();

    return {
      AuthorsStore,
    };
  },
  components: {
    BookshelfTable,
  },
  computed: {
    // Why am I doing this in store instead of more directly? Who knows at this point. Consistency, I guess.
    currentAuthor() {
      return this.AuthorsStore.currentAuthor;
    },
    authorFullName() {
      if (this.currentAuthor) {
        const firstName = this.currentAuthor.first_name || "";
        const lastName = this.currentAuthor.last_name || "";
        return `${firstName} ${lastName}`.trim();
      }
      return "Unknown Author";
    },
  },
  async mounted() {
    try {
      const author = await getAuthorBySlug(this.$route.params.slug);
      this.AuthorsStore.setCurrentAuthor(author.data);
    } catch (error) {
      console.log("ERROR: ", error);
    }
  },
};
</script>
