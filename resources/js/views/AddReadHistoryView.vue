<template>
  <div>
    <div
      class="w-2/3 px-6 py-8 bg-zinc-300 border rounded-md border-zinc-400 mb-4 shadow-lg"
    >
      <div>
        <h1>Add Read History</h1>
        <p>
          Add a read history entry for this book. You can add multiple read
          histories for the same book or version.
        </p>
      </div>
      <div v-if="currentBook">
        <div
          class="p-4 mb-4 bg-white border rounded-md border-zinc-400 shadow-md"
        >
          <h2>Book Information</h2>
          <p>Title: {{ currentBook.title }}</p>
          <p v-for="author in currentAuthors" :key="author.author_id">
            {{ author }}
          </p>
        </div>
        <!-- End book information -->
        <h2>Select a version</h2>
        <div class="grid grid-cols-2">
          <div
            v-for="version in currentVersions"
            :key="version.version_id"
            class="p-4 mb-4 bg-white border rounded-md border-zinc-400 shadow-md"
            :class="{
              'bg-zinc-100 border-2 border-zinc-600':
                version.version_id === selectedVersion?.version_id,
            }"
          >
            <p v-if="version.nickname">{{ version.nickname }}</p>
            <p>{{ version.format.name }}</p>
            <p>{{ version.page_count }}</p>
            <p v-if="version.format_id === 2">{{ version.audio_runtime }}</p>
          </div>
        </div>
        <!-- End versions -->
        <div>
          <UpdateBookReadInstance />
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import { useBooksStore } from "@/stores";

import { getOneBookFromSlug } from "@/api/BookController";

import UpdateBookReadInstance from "@/components/updateBook/UpdateBookReadInstance.vue";

export default {
  name: "AddReadHistoryView",
  setup() {
    const BooksStore = useBooksStore();
    return {
      BooksStore,
    };
  },
  components: {
    UpdateBookReadInstance,
  },
  data() {
    return {
      selectedVersion: null,
    };
  },
  computed: {
    currentBook() {
      return this.BooksStore.allBooks.find(
        (b) => b.slug === this.$route.params.slug
      );
    },
    currentAuthors() {
      return this.currentBook.authors.map((author) => {
        const firstName = author.first_name || "";
        const lastName = author.last_name || "";
        return `${firstName} ${lastName}`.trim();
      });
    },
    currentVersions() {
      return this.currentBook.versions;
    },
  },
  async mounted() {
    if (!this.currentBook) {
      try {
        const book = await getOneBookFromSlug(this.$route.params.slug);
        this.BooksStore.addBook(book.data);
      } catch (error) {
        console.log("ERROR: ", error);
      }
    }
    if (this.currentBook.versions.length === 1) {
      [this.selectedVersion] = this.currentBook.versions;
    }
  },
};
</script>
