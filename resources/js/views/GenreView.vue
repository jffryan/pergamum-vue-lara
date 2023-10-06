<template>
  <div>
    <div v-if="!genre" class="bg-slate-400 p-4 w-1/2 mb-4"></div>
    <h1 v-else class="capitalize">{{ genre.name }}</h1>
    <router-link :to="{ name: 'library.index' }" class="block mb-4"
      >Back to Library</router-link
    >
    <div v-if="books.length > 0">
      <BookshelfTable :books="books" />
    </div>
  </div>
</template>

<script>
import { getOneGenre } from "@/api/GenresController";
import BookshelfTable from "@/components/books/table/BookshelfTable.vue";

export default {
  name: "GenreView",
  components: {
    BookshelfTable,
  },
  data() {
    return {
      genre: null,
      books: [],
    };
  },
  watch: {
    "$route.params.id": {
      immediate: true,
      handler: "fetchGenreData",
    },
  },
  methods: {
    async fetchGenreData() {
      const genreId = this.$route.params.id;
      try {
        const res = await getOneGenre(genreId);
        this.genre = res.data.genre;
        this.books = res.data.books;
      } catch (err) {
        console.log(err.message);
        this.$router.push({ name: "genres.index" });
      }
    },
  },
};
</script>
