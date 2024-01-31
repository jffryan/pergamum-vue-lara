<template>
  <div>
    <h1>All Genres</h1>
    <router-link :to="{ name: 'library.index' }" class="block mb-4"
      >Back to Library</router-link
    >
    <div class="mb-4">
      <input
        type="text"
        placeholder="Search genres..."
        class="border border-gray-400 rounded px-2 py-1 mb-2"
        v-model="searchTerm"
      />
      <div class="flex">
        <span class="text-gray-500 font-bold">Sort by:</span>
        <div class="flex">
          <button
            @click="sortByName"
            class="flex items-center justify-between mx-2 hover:underline"
          >
            <span class="mr-1">Name </span
            ><UpArrow class="w-4 h-4" :class="nameArrow" />
          </button>
          <button
            @click="sortByPopularity"
            class="flex items-center justify-between mx-2 hover:underline"
          >
            <span class="mr-1">Books </span
            ><UpArrow class="w-4 h-4" :class="popularityArrow" />
          </button>
        </div>
      </div>
    </div>
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

import UpArrow from "@/components/globals/svgs/UpArrow.vue";

export default {
  name: "GenresView",
  components: {
    UpArrow,
  },
  setup() {
    const GenreStore = useGenreStore();

    return { GenreStore };
  },
  data() {
    return {
      sortBy: null,
      itemsPerPage: 25,
      searchTerm: "",
    };
  },
  computed: {
    allGenres() {
      return this.GenreStore.allGenres;
    },
    filteredGenres() {
      let filtered = [...this.allGenres];

      if (this.searchTerm) {
        const regex = new RegExp(this.searchTerm, "i");
        filtered = filtered.filter((genre) => regex.test(genre.name));
      }

      const sortFunctions = {
        "name.asc": (a, b) => a.name.localeCompare(b.name),
        "name.desc": (a, b) => b.name.localeCompare(a.name),
        "popularity.asc": (a, b) =>
          a.books_count - b.books_count || a.name.localeCompare(b.name),
        "popularity.desc": (a, b) =>
          b.books_count - a.books_count || b.name.localeCompare(a.name),
      };

      filtered.sort(sortFunctions[this.sortBy]);

      return filtered;
    },
    totalPages() {
      return Math.ceil(this.filteredGenres.length / this.itemsPerPage);
    },
    displayedGenres() {
      const startIndex = (this.GenreStore.currentPage - 1) * this.itemsPerPage;
      const endIndex = startIndex + this.itemsPerPage;

      return this.filteredGenres.slice(startIndex, endIndex);
    },
    nameArrow() {
      if (this.sortBy === "name.asc") return "transform rotate-180";
      if (this.sortBy === "name.desc") return "transform rotate-0";
      return "hidden";
    },
    popularityArrow() {
      if (this.sortBy === "popularity.asc") return "transform rotate-180";
      if (this.sortBy === "popularity.desc") return "transform rotate-0";
      return "hidden";
    },
  },
  methods: {
    sortByName() {
      if (this.sortBy === "name.asc" || this.sortBy === null) {
        this.sortBy = "name.desc";
      } else {
        this.sortBy = "name.asc";
      }
    },
    sortByPopularity() {
      if (this.sortBy === "popularity.asc") {
        this.sortBy = "popularity.desc";
      } else {
        this.sortBy = "popularity.asc";
      }
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
