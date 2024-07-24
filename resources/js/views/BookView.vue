<template>
  <div v-if="!currentBook">
    <div class="bg-slate-400 p-4 w-1/2 mb-4"></div>
    <div class="bg-slate-400 p-4 w-1/2 mb-4"></div>
  </div>
  <div v-else>
    <div class="grid grid-cols-2">
      <div class="mb-12">
        <h1>{{ currentBook.title }}</h1>
        <h2>
          <span v-for="author in currentAuthors" :key="author.author_id"
            ><router-link
              :to="{ name: 'authors.show', params: { slug: author.slug } }"
              class="hover:underline"
              >{{ author.name }}</router-link
            ><span v-if="author !== currentAuthors[currentAuthors.length - 1]"
              >,
            </span>
          </span>
        </h2>
        <p>
          <span class="font-bold">Genres: </span>
          <router-link
            v-for="(genre, index) in currentGenres"
            :key="genre.genre_id"
            :to="{ name: 'genres.show', params: { id: genre.genre_id } }"
            class="capitalize hover:underline"
          >
            {{ genre.name
            }}<span v-if="index < currentGenres.length - 1">, </span>
          </router-link>
        </p>
      </div>
      <div class="pl-12">
        <div class="mb-4">
          <router-link
            class="btn btn-primary mr-4"
            :to="{
              name: 'books.add-read-history',
              params: { slug: currentBook.slug },
            }"
            >Add read history</router-link
          >
          <router-link
            class="btn btn-secondary mr-4"
            :to="{ name: 'books.edit', params: { slug: currentBook.slug } }"
            >Edit book</router-link
          >
          <!--
          <button
            @click="initDeleteBook(currentBook.book_id)"
            class="btn btn-secondary"
          >
            Delete book
          </button>
          -->
        </div>
        <div class="mb-8">
          <router-link :to="{ name: 'library.index' }" class="block mb-4"
            >Back to Library</router-link
          >
        </div>

        <div class="mb-8">
          <h3>Versions</h3>
          <VersionTable :versions="currentBook.versions" />
        </div>
        <div v-if="currentBook.is_completed">
          <div class="p-4 rounded-t-md bg-slate-900 text-slate-200">
            <h3 class="mb-0">Read History</h3>
          </div>

          <div class="p-4 rounded-b-md bg-slate-200">
            <div
              v-for="readInstance in readHistory"
              :key="readInstance.date_read"
            >
              <p>
                <span class="text-zinc-600">Date read: </span
                >{{ readInstance.date_read }}
              </p>
              <p>
                <span class="text-zinc-600">Version: </span
                >{{ readInstance.version }}
              </p>
            </div>
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
    readHistory() {
      if (this.currentBook.read_instances.length === 0) return "";

      // Loop through all read instances and return an array in MM/DD/YYYY format
      const readInstances = this.currentBook.read_instances;
      const formattedReadInstances = [];

      for (let i = 0; i < readInstances.length; i += 1) {
        // Grab the instance
        const readInstance = readInstances[i];
        // Format date
        const unformattedDate = readInstance.date_read;
        const [year, month, day] = unformattedDate.split("-");
        const formattedDateRead = `${month}/${day}/${year}`;
        // Find the version
        const readInstanceVersion = this.findReadInstanceVersion(
          readInstance.version_id
        );
        // Grab the version's format name
        const versionFormatName = readInstanceVersion.format.name;
        // Format the read instance
        const formattedReadInstance = {
          date_read: formattedDateRead,
          version: versionFormatName,
        };
        formattedReadInstances.push(formattedReadInstance);
      }
      // MM/DD/YYYY

      return formattedReadInstances;
    },
  },
  methods: {
    initDeleteBook() {
      this.ConfirmationModalStore.showConfirmationModal("confirmDeleteBook", {
        book_id: this.currentBook.book_id,
        title: this.currentBook.title,
      });
    },
    findReadInstanceVersion(version_id) {
      return this.currentBook.versions.find(
        (version) => version.version_id === version_id
      );
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
