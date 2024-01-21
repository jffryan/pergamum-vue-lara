<template>
  <div>
    <h1>Completed</h1>
    <ul
      class="flex flex-wrap text-sm font-medium text-center border-b border-gray-700 mb-8"
    >
      <li v-for="year in loggedYears" :key="year" class="me-2">
        <button
          @click="setActiveYear(year)"
          :class="{
            'bg-slate-900 text-blue-200': year === activeYear,
            'hover:bg-slate-900 hover:text-gray-300': year !== activeYear,
          }"
          class="inline-block p-4 rounded-t-md"
        >
          {{ year }}
        </button>
      </li>
    </ul>
    <div>
      <BookshelfTable :books="activeBooks" :bookshelfTitle="null" />
    </div>
  </div>
</template>

<script>
import { getBooksByYear } from "@/api/BookController";

import BookshelfTable from "@/components/books/table/BookshelfTable.vue";

export default {
  name: "CompletedView",
  components: {
    BookshelfTable,
  },
  data() {
    return {
      loggedYears: [2018, 2019, 2020, 2021, 2022, 2023, 2024],
      activeYear: 2024,
      activeBooks: [],
    };
  },
  methods: {
    setActiveYear(year) {
      this.activeYear = year;
    },
    async fetchandSetBooksByYear(year) {
      const books = await getBooksByYear(year.toString());
      this.activeBooks = books.data;
    },
  },
  watch: {
    activeYear: {
      immediate: true,
      handler(newYear) {
        this.fetchandSetBooksByYear(newYear);
      },
    },
  },
};
</script>
