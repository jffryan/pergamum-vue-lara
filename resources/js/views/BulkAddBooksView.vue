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
  </div>
</template>

<script>
import { createBooks } from "@/api/BookController";

export default {
  data() {
    return {
      selectedFile: null,
    };
  },
  methods: {
    fileChanged(event) {
      [this.selectedFile] = event.target.files;
    },
    async uploadCsv() {
      if (!this.selectedFile) {
        console.log("Please select a CSV file first.");
        return;
      }

      const reader = new FileReader();
      reader.readAsText(this.selectedFile);

      reader.onload = async () => {
        const csvData = reader.result;

        // Convert CSV data to an array of books
        const lines = csvData.split("\n");
        const header = lines[0].split(",");
        const books = [];

        for (let i = 1; i < lines.length; i += 1) {
          const book = {};
          const values = lines[i].split(",");

          for (let j = 0; j < header.length; j += 1) {
            book[header[j].trim()] = values[j].trim();
          }

          books.push(book);
        }

        // Send the array of books to the backend
        try {
          const response = await createBooks(books);
          console.log("Books uploaded:", response);
        } catch (error) {
          console.error("An error occurred:", error);
        }
      };

      reader.onerror = () => {
        console.log("Could not read the file. Please try again.");
      };
    },
  },
};
</script>
