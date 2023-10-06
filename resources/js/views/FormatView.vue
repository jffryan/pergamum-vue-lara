<template>
  <div>
    <h1>Format View</h1>
    <router-link :to="{ name: 'library.index' }" class="block mb-4"
      >Back to Library</router-link
    >
    <BookshelfTable
      :books="booksByFormat"
      :bookshelfTitle="$route.params.format + ' Books'"
      class="mb-4"
    />
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
import { getBooksByFormat } from "@/api/BookController";

import { useBooksStore } from "@/stores";

import BookshelfTable from "@/components/books/table/BookshelfTable.vue";

export default {
  name: "FormatView",
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
    };
  },
  computed: {
    booksByFormat() {
      return this.BooksStore.allBooks;
    },
  },
  methods: {
    async fetchData() {
      const options = {
        page: this.$route.query.page || 1,
        format: this.$route.params.format,
      };
      const res = await getBooksByFormat(options);
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
    try {
      const response = await this.fetchData();
      console.log("RESPONSE", response.data);
    } catch (error) {
      console.log(error.message);
    }
  },
};
</script>
