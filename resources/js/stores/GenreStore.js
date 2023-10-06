import { defineStore } from "pinia";

const useGenreStore = defineStore("GenreStore", {
  state: () => ({
    allGenres: [],
    currentPage: 1,
  }),
  getters: {
    // getters go here
  },
  actions: {
    setAllGenres(genres) {
      this.allGenres = genres;
    },
  },
});

export default useGenreStore;
