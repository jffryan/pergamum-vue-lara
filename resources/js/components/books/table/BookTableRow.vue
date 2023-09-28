<template>
  <div class="grid grid-cols-12">
    <div class="col-span-3 p-2">
      <router-link
        :to="{ name: 'books.show', params: { slug: book.slug } }"
        class="h-full w-full"
      >
        {{ book.title }}
      </router-link>
    </div>
    <div class="col-span-2 p-2">{{ authorName }}</div>
    <div class="col-span-1 p-2">{{ bookFormat }}</div>
    <div class="col-span-3 p-2">
      <span v-for="(genre, idx) in primaryGenres" :key="idx" class="capitalize">
        {{ genre }}<span v-if="idx < primaryGenres.length - 1">, </span>
      </span>
    </div>
    <div class="col-span-2 p-2">{{ book.date_completed }}</div>
    <div class="col-span-1 p-2">{{ book.rating }}</div>
  </div>
</template>

<script>
export default {
  name: "BookTableRow",
  props: {
    book: {
      type: Object,
      required: true,
    },
  },
  computed: {
    authorName() {
      const authorResponse = this.book.authors[0];
      if (authorResponse) {
        const firstName = authorResponse.first_name || "";
        const lastName = authorResponse.last_name || "";
        return `${firstName} ${lastName}`.trim();
      }
      return "Unknown";
    },
    bookFormat() {
      if (this.book.versions && this.book.versions.length > 0) {
        return this.book.versions[0].format.name;
      }
      return "Unknown";
    },
    primaryGenres() {
      // Take the first 3 genres and return their names in an array
      // @TODO: Take their IDs as well, and return an array of objects that includes IDs to use as url params
      const genreNames = this.book.genres
        .slice(0, 3)
        .map((genre) => genre.name);
      return genreNames;
    },
  },
};
</script>
