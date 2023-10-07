<template>
  <div>
    <h1>Upload books</h1>

    <form @submit.prevent="uploadCsv">
      <div class="mb-8">
        <label class="block mb-4 font-bold text-zinc-600" for="csvFile">
          Upload a CSV File
        </label>
        <input
          id="csvFile"
          type="file"
          accept=".csv"
          class="rounded w-full text-gray-700 leading-tight focus:outline-none focus:shadow-outline"
          @change="fileChanged"
        />
      </div>
      <button
        type="submit"
        class="text-white bg-slate-800 hover:bg-slate-900ont-medium rounded-lg text-sm px-5 py-2.5 mb-2 focus:outline-none focus:border-zinc-600 focus:rounded-md transition-all"
      >
        Upload
      </button>
    </form>
    <div v-if="displayMessage">
      {{ displayMessage }}
    </div>
  </div>
</template>

<script>
import Papa from "papaparse";

import {
  splitAndNormalizeGenres,
  splitName,
} from "@/utils/BookFormattingLibrary";
import { createBooks } from "@/api/BookController";
import { useConfigStore } from "@/stores";

export default {
  setup() {
    const ConfigStore = useConfigStore();

    return {
      ConfigStore,
    };
  },
  data() {
    return {
      selectedFile: null,
      displayMessage: null,
    };
  },
  computed: {
    formats() {
      return this.ConfigStore.books.formats;
    },
  },
  methods: {
    fileChanged(event) {
      [this.selectedFile] = event.target.files;
    },
    async uploadCsv() {
      const vm = this;
      if (!this.selectedFile) {
        console.log("Please select a CSV file first.");
        return;
      }

      const reader = new FileReader();
      reader.readAsText(this.selectedFile);

      reader.onload = async () => {
        const parsedData = Papa.parse(reader.result, { header: true });
        const books = parsedData.data;
        const formattedBooks = books.map((book) => {
          const authorsArray = book.authors
            .split(",")
            .map((author) => author.trim());

          const { format_id } = vm.formats.find(
            ({ name }) => name.toLowerCase() === book.format.toLowerCase()
          );

          return {
            authors: authorsArray.map((author) => splitName(author)),
            book: {
              date_completed: book.date_completed,
              // NEED TO FIX
              genres: {
                parsed: splitAndNormalizeGenres(book.genres),
              },
              is_completed: book.is_completed === "TRUE",
              rating: book.rating,
              title: book.title,
            },
            versions: [
              {
                audio_runtime: book.audio_runtime,
                format: format_id,
                nickname: book.nickname,
                page_count: book.page_count,
              },
            ],
          };
        });

        // Send the array of books to the backend
        try {
          const response = await createBooks(formattedBooks);
          // This is all kinds of fucked up because I need to fix the backend
          const booksRes = response.data.books;

          if (booksRes.length > 1) {
            const message = `${booksRes.length} books were added to your library.`;
            this.displayMessage = message;
          }
          if (booksRes.length === 1) {
            const message = `${booksRes[0].title} was added to your library.`;
            this.displayMessage = message;
          }
        } catch (error) {
          console.error("An error occurred:", error);
        }
      };

      reader.onerror = () => {
        console.log("Could not read the file. Please try again.");
      };
    },
  },
  async created() {
    await this.ConfigStore.checkForFormats();
  },
};
</script>
