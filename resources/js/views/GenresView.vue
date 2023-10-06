<template>
  <div>
    <h1>Genres View</h1>
    <router-link :to="{ name: 'library.index' }" class="block mb-4"
      >Back to Library</router-link
    >
    <div class="genrelist-container flex flex-col justify-between">
      <ul>
        <li v-for="genre in displayedGenres" :key="genre.genre_id">
          <router-link
            :to="{ name: 'genres.show', params: { id: genre.genre_id } }"
          >
            <span class="capitalize">{{ genre.name }}</span> ({{
              genre.books_count
            }})
          </router-link>
        </li>
      </ul>
      <div class="mt-auto">
        <div class="mt-4">
          <button
            v-for="num in totalPages"
            :key="num"
            @click="GenreStore.currentPage = num"
            class="mx-1"
            :class="{ 'font-bold': GenreStore.currentPage === num }"
          >
            {{ num }}
          </button>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import { getAllGenres } from "@/api/GenresController";

import { useGenreStore } from "@/stores";

export default {
  name: "GenresView",
  setup() {
    const GenreStore = useGenreStore();

    return { GenreStore };
  },
  data() {
    return {
      itemsPerPage: 25,
    };
  },
  computed: {
    allGenres() {
      return this.GenreStore.allGenres;
    },
    totalPages() {
      return Math.ceil(this.allGenres.length / this.itemsPerPage);
    },
    // @TODO: Set up active filters
    displayedGenres() {
      const filteredGenres = this.allGenres;

      // Now paginate
      const startIndex = (this.GenreStore.currentPage - 1) * this.itemsPerPage;
      const endIndex = startIndex + this.itemsPerPage;

      return filteredGenres.slice(startIndex, endIndex);
    },
  },
  async mounted() {
    const res = await getAllGenres();
    this.GenreStore.setAllGenres(res.data);
  },
};
</script>

<style scoped>
.genrelist-container {
  min-height: 60vh;
}
</style>
