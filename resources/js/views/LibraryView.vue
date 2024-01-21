<template>
  <div>
    <h1>Library</h1>
    <div class="mb-4">
      <input
        type="text"
        placeholder="Search books..."
        class="border border-gray-400 rounded px-2 py-1 mb-2"
        v-model="searchTerm"
      />
    </div>
    <BookshelfTable :books="displayedBooks" class="mb-4" />
    <div v-for="link in cleanedLinks" :key="link.label" class="inline mr-2">
      <router-link
        v-if="link.url"
        :to="link.url"
        :class="link.active ? 'font-bold underline' : ''"
      >
        {{ link.label.replace("&laquo;", "").replace("&raquo;", "") }}
      </router-link>
    </div>
  </div>
</template>

<script>
import { getAllBooks } from "@/api/BookController";

import { useBooksStore } from "@/stores";

import BookshelfTable from "@/components/books/table/BookshelfTable.vue";

export default {
  name: "LibraryView",
  components: {
    BookshelfTable,
  },
  setup() {
    const BooksStore = useBooksStore();

    return {
      BooksStore,
    };
  },
  data() {
    return {
      cleanedLinks: [],
      searchTerm: "",
    };
  },
  computed: {
    allBooks() {
      return this.BooksStore.allBooks;
    },
    displayedBooks() {
      // This only works if the book you're looking for is on the page you're actively on.
      // That doesn't really work for users...
      let filteredBooks = [...this.allBooks];

      if (this.searchTerm) {
        filteredBooks = filteredBooks.filter((book) => {
          return book.title
            .toLowerCase()
            .includes(this.searchTerm.toLowerCase());
        });
      }

      return filteredBooks;
    },
  },

  methods: {
    async fetchData() {
      const options = {
        page: this.$route.query.page || 1,
      };

      const res = await getAllBooks(options);

      this.BooksStore.setAllBooks(res.data.data);
      this.cleanedLinks = this.cleanPaginationLinks(res.data.links);
    },
    cleanPaginationLinks(links) {
      return links.map((link) => {
        let url = null;
        let page = null;
        if (link.url) {
          url = new URL(link.url);
          page = url.searchParams.get("page");
        }

        return {
          ...link,
          url: page ? `/library?page=${page}` : null,
          label: link.label.replace("&laquo;", "").replace("&raquo;", ""),
        };
      });
    },
  },

  watch: {
    "$route.query.page": "fetchData",
  },
  async mounted() {
    await this.fetchData();
  },
};
</script>
