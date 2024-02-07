<template>
  <div class="grid grid-cols-12">
    <div class="col-span-3 p-2">
      <router-link
        :to="{ name: 'books.show', params: { slug: book.slug } }"
        class="block h-full w-full"
      >
        {{ book.title }}
      </router-link>
    </div>
    <div class="col-span-2 p-2">
      <router-link
        :to="{ name: 'authors.show', params: { slug: authorInfo.slug } }"
        class="block h-full w-full"
        >{{ authorInfo.name }}</router-link
      >
    </div>
    <div class="col-span-1 p-2">
      <router-link
        :to="{ name: 'formats.show', params: { format: bookFormat.slug } }"
        class="block h-full w-full"
        >{{ bookFormat.name }}</router-link
      >
    </div>
    <div class="col-span-1 p-2">{{ pageCount }}</div>
    <div class="col-span-3 p-2">
      <span
        v-for="(genre, idx) in primaryGenres"
        :key="genre.id"
        class="capitalize"
      >
        <router-link :to="{ name: 'genres.show', params: { id: genre.id } }">{{
          genre.name
        }}</router-link
        ><span v-if="idx < primaryGenres.length - 1">, </span>
      </span>
    </div>

    <div class="col-span-1 p-2">{{ formattedMostRecentDateRead }}</div>
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
    authorInfo() {
      const authorResponse = this.book.authors[0];
      if (authorResponse) {
        const firstName = authorResponse.first_name || "";
        const lastName = authorResponse.last_name || "";
        const fullName = `${firstName} ${lastName}`.trim();
        const authorInfo = {
          name: fullName,
          slug: authorResponse.slug,
        };
        return authorInfo;
      }
      return "Unknown";
    },
    bookFormat() {
      if (this.book.versions && this.book.versions.length > 0) {
        const { format } = this.book.versions[0];
        const formatInfo = {
          name: format.name,
          slug: format.slug,
        };
        return formatInfo;
      }
      return "Unknown";
    },
    primaryGenres() {
      // Take the first 3 genres and return their names + ids in an array
      const genreNames = this.book.genres
        .slice(0, 2)
        .map((genre) => ({ name: genre.name, id: genre.genre_id }));
      return genreNames;
    },
    formattedMostRecentDateRead() {
      if (
        this.book.read_instances === undefined ||
        this.book.read_instances.length === 0
      )
        return "";

      // Get the most recent date read and format it as MM/DD/YYYY
      const readInstance = this.book.read_instances[0];
      // MM/DD/YY
      const unformattedDate = readInstance.date_read;
      const [year, month, day] = unformattedDate.split("-");
      const lastTwoDigitsOfYear = year.slice(-2);
      const formattedDateRead = `${month}/${day}/${lastTwoDigitsOfYear}`;
      return formattedDateRead;
    },
    pageCount() {
      return this.book.versions[0].page_count || "";
    },
  },
};
</script>
