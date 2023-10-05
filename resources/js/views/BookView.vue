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
          <router-link
            :to="{ name: 'authors.show', params: { slug: author.slug } }"
            >{{ author.name }}</router-link
          >
        </h2>
        <p>
          <span class="font-bold">Genres: </span>
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
      <div class="pl-12">
        <div class="mb-8">
          <router-link
            class="btn btn-primary mr-4"
            :to="{ name: 'books.edit', params: { slug: currentBook.slug } }"
            >Edit book</router-link
          >
          <button
            @click="initDeleteBook(currentBook.book_id)"
            class="btn btn-secondary"
          >
            Delete book
          </button>
        </div>

        <div class="mb-8">
          <h3>Versions</h3>
          <VersionTable :versions="currentBook.versions" />
        </div>
        <div v-if="currentBook.is_completed">
          <div class="p-4 rounded-t-md bg-slate-900 text-slate-200">
            <h3 class="mb-0">Reader Profile</h3>
          </div>

          <div class="p-4 rounded-b-md bg-slate-200">
            <p>
              <span class="text-zinc-600">Date read: </span
              >{{ currentBook.date_completed }}
            </p>
            <p>
              <span class="text-zinc-600">Rating: </span
              >{{ currentBook.rating }}
            </p>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
import { useBooksStore, useConfirmationModalStore } from "@/stores";

import { getOneBookFromSlug } from "@/api/BookController";

import VersionTable from "@/components/books/table/VersionTable.vue";

export default {
  name: "BookView",
  setup() {
    const BooksStore = useBooksStore();
    const ConfirmationModalStore = useConfirmationModalStore();

    return {
      BooksStore,
      ConfirmationModalStore,
    };
  },
  components: {
    VersionTable,
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
          const slug = author.slug || "";
          return { name: `${firstName} ${lastName}`.trim(), slug };
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
  methods: {
    initDeleteBook() {
      this.ConfirmationModalStore.showConfirmationModal("confirmDeleteBook", {
        book_id: this.currentBook.book_id,
        title: this.currentBook.title,
      });
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
  },
};
</script>
