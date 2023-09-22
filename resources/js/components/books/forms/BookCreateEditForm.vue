<template>
  <form @submit.prevent="submitBookForm(this.bookForm)">
    <div class="w-full flex justify-between align-bottom mt-4 mb-6">
      <h2 class="mb-0">
        {{ isCreateMode ? "Create Book" : "Edit Book" }}
      </h2>
      <div class="mt-auto">
        <label class="relative inline-flex items-center cursor-pointer">
          <input
            type="checkbox"
            v-model="bookForm.book.is_completed"
            class="sr-only peer"
          />
          <div
            class="w-11 h-6 bg-slate-400 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-gray-700"
          ></div>
          <span class="ml-3 font-medium">I finished this book</span>
        </label>
      </div>
    </div>
    <!-- End header -->
    <div class="mb-4">
      <label for="title" class="block mb-2 font-bold text-zinc-600 mr-6"
        >Title</label
      >
      <input
        type="text"
        id="title"
        name="title"
        placeholder="Title"
        v-model="bookForm.book.title"
      />
      <p v-if="!isValid.book.title" class="p-2 text-red-300">
        Enter a name for this book.
      </p>
    </div>
    <!-- End title -->
    <div class="mb-4">
      <div class="flex justify-between mb-2">
        <label
          for="author_first_name"
          class="block font-bold text-zinc-600 mr-6"
          >Author</label
        >
        <div class="flex justify-end">
          <button class="btn-inline" @click="addAuthorInput">Add more</button>
        </div>
      </div>

      <div
        v-for="(author, idx) in bookForm.authors"
        :key="author.author_id ? author.author_id : idx"
        ref="author_fields"
      >
        <div class="w-full text-right">
          <button
            class="btn-inline"
            v-if="bookForm.authors.length > 1"
            @click="removeAuthorInput(idx)"
          >
            Remove
          </button>
        </div>

        <div class="flex justify-between gap-x-4">
          <input
            id="author_first_name"
            name="author_first_name"
            type="text"
            placeholder="First"
            class="block"
            v-model="author.first_name"
          />
          <input
            id="author_last_name"
            name="author_last_name"
            type="text"
            placeholder="Last"
            class="block"
            v-model="author.last_name"
          />
        </div>

        <p v-if="!isValid.authors[idx]" class="p-2 text-red-300">
          Last name is required.
        </p>
      </div>
    </div>
    <!-- END AUTHOR INPUT -->
    <div class="mb-4">
      <label for="genres" class="block font-bold text-zinc-600 mb-2"
        >Add genres, separated by a comma</label
      >
      <input
        name="genres"
        type="text"
        placeholder="Genres"
        class="block bg-dark-mode-100 w-full border-b border-zinc-400 p-2 mb-4"
        v-model="bookForm.book.genres.raw"
      />
      <p v-if="!isValid.book.genres" class="p-2 text-red-300">
        At least one genre is required.
      </p>
    </div>
    <!-- END GENRES -->
    <div class="mb-4">
      <label for="title" class="block mb-2 font-bold text-zinc-600 mr-6"
        >Format</label
      >
      <select
        v-model="bookForm.version.format"
        class="bg-zinc-100 text-zinc-700 border rounded p-2 focus:border-zinc-500 focus:outline-none"
      >
        <option value="" class="text-zinc-400" disabled>Select a format</option>
        <option
          v-for="format in configStore.books.formats"
          :key="format.format_id"
          :value="format.format_id"
          class="text-zinc-700"
        >
          {{ format.name }}
        </option>
      </select>
      <p v-if="!isValid.version.format" class="p-2 text-red-300">
        Format is required.
      </p>
    </div>
    <!-- END FORMAT SELECT -->
    <div class="flex justify-between gap-x-4 mb-4">
      <!-- Page count field -->
      <div class="mb-4 w-full">
        <label for="page_count" class="block mb-2 font-bold text-zinc-600 mr-6"
          >Page Count</label
        >
        <input
          type="text"
          id="page_count"
          name="page_count"
          placeholder="Page Count"
          v-model="bookForm.version.page_count"
          @input="
            bookForm.page_count = $event.target.value.replace(/[^0-9]/g, '')
          "
        />
        <p v-if="!isValid.version.page_count" class="p-2 text-red-300">
          Enter a valid page count.
        </p>
      </div>

      <!-- Audio runtime field -->
      <div v-if="bookForm.version.format === 2" class="mb-4 w-full">
        <label
          for="audio_runtime"
          class="block mb-2 font-bold text-zinc-600 mr-6"
          >Audio Runtime</label
        >
        <input
          type="text"
          id="audio_runtime"
          name="audio_runtime"
          placeholder="Audio Runtime"
          v-model="bookForm.audio_runtime"
          @input="
            bookForm.audio_runtime = $event.target.value.replace(/[^0-9]/g, '')
          "
        />
        <p v-if="!isValid.version.audio_runtime" class="p-2 text-red-300">
          Enter a valid audio runtime.
        </p>
      </div>
    </div>
    <!-- END CONTENT LENGTH -->
    <div v-if="bookForm.book.is_completed">
      <div class="mb-4">
        <label
          for="date_completed"
          class="block mb-2 font-bold text-zinc-600 mr-6"
          >Date Completed</label
        >
        <input
          type="text"
          id="date_completed"
          name="date_completed"
          :value="bookForm.book.date_completed"
          @input="updateDateCompleted"
          placeholder="MM/DD/YYYY"
          class="border-2 border-t-transparent border-x-transparent border-b-zinc-400 p-2 w-full mb-2 focus:border-2 focus:outline-none focus:border-zinc-600 focus:rounded-md transition-all"
        />
      </div>
      <!-- END DATE COMPLETED -->
      <div class="mb-4">
        <label for="rating" class="block mb-2 font-bold text-zinc-600 mr-6"
          >Rating</label
        >
        <select
          v-model="bookForm.book.rating"
          class="bg-zinc-100 text-zinc-700 border rounded p-2 focus:border-zinc-500 focus:outline-none"
        >
          <option value="" class="text-zinc-400" disabled>
            Select a rating
          </option>
          <option
            v-for="(rating, idx) in Array.from(
              { length: 9 },
              (_, i) => 1 + i * 0.5
            )"
            :key="idx"
            :value="rating"
            class="text-zinc-700"
          >
            {{ rating }}
          </option>
        </select>
      </div>
      <!-- END RATING INPUT -->
    </div>
    <!-- END COMPLETED CONDITIONAL -->
    <div class="mt-8 flex justify-end">
      <button
        type="submit"
        class="text-white bg-slate-800 hover:bg-slate-900ont-medium rounded-lg text-sm px-5 py-2.5 mb-2 focus:outline-none focus:border-zinc-600 focus:rounded-md transition-all"
      >
        Submit
      </button>
    </div>
  </form>
</template>

<script>
import _ from "lodash";

import { useConfigStore } from "@/stores";

import { splitAndNormalizeGenres } from "@/utils/genresLibrary";

import { createBook } from "@/api/BookController";

import {
  validateString,
  validateAuthor,
  validateNumber,
} from "@/utils/validators";

export default {
  name: "BookCreateEditForm",
  setup() {
    const configStore = useConfigStore();

    return {
      configStore,
    };
  },
  data() {
    return {
      bookForm: this.initializeBookForm(),
      isValid: {
        book: {
          title: true,
          genres: true,
        },
        authors: [true],
        version: {
          format: true,
          page_count: true,
          audio_runtime: true,
        },
      },
    };
  },
  computed: {
    isCreateMode() {
      // This only works for now. Eventually I want to use this form on other pages
      return this.$route.name === "books.create";
    },
  },
  methods: {
    // Default form data
    initializeBookForm() {
      return {
        book: {
          title: "",
          authors: [""],
          genres: {
            raw: "",
            parsed: [""],
          },
          is_completed: false,
          date_completed: "",
          rating: "",
        },
        authors: [
          {
            first_name: "",
            last_name: "",
          },
        ],
        version: {
          format: "",
          page_count: "",
          audio_runtime: "",
        },
      };
    },
    // Form UX functions
    addAuthorInput(e) {
      e.preventDefault();

      // Limit to one blank input at a time
      const lastIndex = this.bookForm.authors.length - 1;
      const lastAuthor = this.bookForm.authors[lastIndex];
      if (lastAuthor.first_name !== "" || lastAuthor.last_name !== "") {
        const newAuthor = {
          first_name: "",
          last_name: "",
        };
        this.bookForm.authors.push(newAuthor);
      }
      // Add another isValid input
      this.isValid.authors.push({
        last_name: true,
      });
    },
    removeAuthorInput(index) {
      if (this.bookForm.authors.length > 1) {
        this.bookForm.authors.splice(index, 1);

        // Also remove the corresponding validation entry
        this.isValid.authors.splice(index, 1);
      }
    },
    updateDateCompleted(event) {
      const { value } = event.target;
      const adjustedValue = this.addSlashes(value);
      // A regex pattern that allows partially entered valid dates
      const partialRegex =
        /^((0[1-9]|1[0-2])\/?)?((0[1-9]|[12][0-9]|3[01])\/?)?((19|20)?\d{0,2})?$/;

      if (partialRegex.test(adjustedValue)) {
        this.bookForm.book.date_completed = adjustedValue;
      } else {
        // Try to rewrite this to fix the lint issue
        event.target.value = this.bookForm.book.date_completed;
      }
    },
    // Helper function for date input.
    addSlashes(value) {
      if (value.length === 2 || value.length === 5) {
        return `${value}/`;
      }
      return value;
    },
    // Form validation
    validateBook(bookForm) {
      const { title, genres } = bookForm.book;
      const { format, page_count, audio_runtime } = bookForm.version;

      const isValid = {
        book: {
          title: validateString(title),
          genres: validateString(genres.raw),
        },
        authors: bookForm.authors.map((author) => validateAuthor(author)),
        version: {
          format: validateNumber(format),
          page_count: validateString(page_count),
          audio_runtime: validateNumber(audio_runtime),
        },
      };

      this.isValid = isValid;
      return Object.values(isValid.book).every((value) => value);
    },
    // Formatting submission
    formatBookForm(bookForm, parsedGenres) {
      const formattedBookForm = _.cloneDeep(bookForm);

      formattedBookForm.book.genres.parsed = parsedGenres;

      return formattedBookForm;
    },
    // Form submit process
    async submitBookForm(bookForm) {
      const bookFormRequest = _.cloneDeep(bookForm);

      if (this.isCreateMode) {
        // Validate
        const isFormValid = this.validateBook(bookFormRequest);
        if (isFormValid) {
          // Set formatting
          const parsedGenres = splitAndNormalizeGenres(
            bookForm.book.genres.raw
          );
          const formattedBookForm = this.formatBookForm(
            bookFormRequest,
            parsedGenres
          );

          const res = await createBook(formattedBookForm);

          this.bookForm = this.initializeBookForm();

          return res;
        }
      } else {
        throw new Error("Form is invalid.");
      }
    },
  },
  async created() {
    await this.configStore.checkForFormats();
  },
};
</script>

<style scoped>
input[type="text"] {
  @apply border-2 border-t-transparent border-x-transparent border-b-zinc-400 p-2 w-full mb-2 focus:border-2 focus:outline-none focus:border-zinc-600 focus:rounded-md transition-all;
}
</style>
