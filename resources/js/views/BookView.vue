<template>
  <div v-if="!currentBook">
    <div class="bg-slate-400 p-4 w-1/2 mb-4"></div>
    <div class="bg-slate-400 p-4 w-1/2 mb-4"></div>
  </div>
  <div v-else>
    <div class="grid grid-cols-2">
      <div class="mb-12">
        <h1>{{ currentBook.title }}</h1>
        <h2 v-for="author in currentAuthors" :key="author.author_id">
          {{ author }}
        </h2>
        <p>
          <router-link
            v-for="(genre, index) in currentGenres"
            :key="genre.genre_id"
            to="/"
            class="capitalize"
          >
            {{ genre.name
            }}<span v-if="index < currentGenres.length - 1">, </span>
          </router-link>
        </p>
      </div>
      <div>
        <div class="mb-8">
          <router-link
            class="p-2 border border-zinc-400 rounded-md hover:bg-zinc-600 hover:text-white transition-colors duration-200 ease-in-out"
            :to="{ name: 'books.edit', params: { slug: currentBook.slug } }"
          >
            Edit book
          </router-link>
        </div>

        <div
          v-if="currentBook.is_completed"
          class="w-1/2 p-8 border rounded-md border-slate-400 bg-slate-200 mx-auto"
        >
          <p>
            <span class="text-zinc-600">Date read: </span
            >{{ currentBook.date_completed }}
          </p>
          <p>
            <span class="text-zinc-600">Rating: </span>{{ currentBook.rating }}
          </p>
        </div>
      </div>

      <div class="col-span-2">
        <h3 class="w-1/2">Versions</h3>
        <div class="grid grid-cols-3">
          <div
            v-for="version in currentBook.versions"
            :key="version.version_id"
          >
            <p v-if="version.nickname" class="text-lg font-bold">
              {{ version.nickname }}
            </p>
            <p>
              <span class="text-zinc-600">Format: </span
              >{{ version.format?.name }}
            </p>
            <p>
              <span class="text-zinc-600">Page count: </span
              >{{ version.page_count }}
            </p>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import { useBooksStore } from "@/stores";

import { getBookBySlug } from "@/api/BookController";

export default {
  name: "BookView",
  setup() {
    const BooksStore = useBooksStore();

    return {
      BooksStore,
    };
  },
  data() {
    return {
      currentSlug: this.$route.params.slug,
    };
  },
  computed: {
    currentBook() {
      return this.BooksStore.allBooks.find(
        (b) => b.slug === this.$route.params.slug
      );
    },
    currentAuthors() {
      if (this.currentBook) {
        return this.currentBook.authors.map((author) => {
          const firstName = author.first_name || "";
          const lastName = author.last_name || "";
          return `${firstName} ${lastName}`.trim();
        });
      }
      return [];
    },
    currentGenres() {
      if (this.currentBook) {
        return this.currentBook.genres;
      }
      return [];
    },
  },
  async mounted() {
    if (!this.currentBook) {
      try {
        const book = await getBookBySlug(this.$route.params.slug);
        this.BooksStore.addBook(book.data);
      } catch (error) {
        console.log("ERROR: ", error);
      }
    }
  },
};
</script>
