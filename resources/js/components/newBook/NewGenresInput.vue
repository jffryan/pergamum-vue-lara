<template>
  <form
    @submit.prevent="submitGenres"
    class="p-4 mb-4 bg-white border rounded-md border-zinc-400 shadow-md"
  >
    <div class="mb-4">
      <label for="genres" class="block font-bold text-zinc-600 mb-2"
        >Add genres, separated by a comma</label
      >
      <input
        name="genres"
        type="text"
        placeholder="Genres"
        class="block bg-dark-mode-100 w-full border-b border-zinc-400 p-2 mb-4"
        @input="resetValidation"
        v-model="genres.raw"
      />
      <p v-if="!isValid" class="p-2 text-red-300">
        At least one genre is required.
      </p>
    </div>
    <!-- End genres -->
    <div class="flex justify-end">
      <button class="btn btn-primary" type="submit">Submit</button>
    </div>
  </form>
</template>

<script>
import { splitAndNormalizeGenres } from "@/utils/BookFormattingLibrary";
import { useNewBookStore } from "@/stores";

export default {
  name: "NewGenresInput",
  setup() {
    const NewBookStore = useNewBookStore();

    return {
      NewBookStore,
    };
  },
  data() {
    return {
      genres: {
        raw: "",
        parsed: [],
      },
      isValid: true,
    };
  },
  methods: {
    parseGenres() {
      // Could make this agnostic but why bother?
      this.genres.parsed = splitAndNormalizeGenres(this.genres.raw);
    },
    validateGenres() {
      this.isValid = this.genres.parsed.length > 0;
    },
    resetValidation() {
      this.isValid = true;
    },
    async submitGenres() {
      this.parseGenres();
      this.validateGenres();
      if (!this.isValid) {
        return;
      }
      await this.NewBookStore.addGenresToNewBook(this.genres.parsed);
    },
  },
};
</script>
