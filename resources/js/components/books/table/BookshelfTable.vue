<template>
  <div>
    <h2>All books</h2>
    <div>
      <div class="grid grid-cols-12 bg-slate-900 text-slate-200 rounded-t-md">
        <div
          v-for="column in columns"
          :key="column.name"
          @click="column.clickHandler"
          :class="[
            'p-2 flex justify-between align-bottom',
            column.clickHandler ? 'cursor-pointer' : '',
            `col-span-${column.span}`,
          ]"
        >
          {{ column.name }}
          <UpArrow
            v-if="arrowPosition(column.ascending, column.descending)"
            :class="[
              `arrow-${arrowPosition(column.ascending, column.descending)}`,
              'fill-white',
            ]"
          />
        </div>
      </div>
      <BookTableRow
        v-for="(book, idx) in books"
        :key="book.book_id"
        :book="book"
        :class="[
          idx % 2 === 0 ? 'bg-slate-200' : 'bg-slate-300',
          ' text-black cursor-pointer hover:bg-slate-600 hover:text-white',
        ]"
      />
    </div>
  </div>
</template>

<script>
import { useBooksStore } from "@/stores";

import BookTableRow from "@/components/books/table/BookTableRow.vue";
import UpArrow from "@/components/globals/svgs/UpArrow.vue";

export default {
  name: "BookshelfTable",
  components: {
    BookTableRow,
    UpArrow,
  },
  props: {
    books: {
      type: Array,
      required: true,
    },
  },
  setup() {
    const BooksStore = useBooksStore();

    return {
      BooksStore,
    };
  },
  data() {
    return {
      columns: [
        {
          name: "Title",
          span: 3,
          clickHandler: this.toggleSortByTitle,
          ascending: "sortByTitleAlpha",
          descending: "sortByTitleAlphaDesc",
        },
        {
          name: "Primary Author",
          span: 2,
          clickHandler: this.toggleSortByAuthor,
          ascending: "sortByAuthorLastName",
          descending: "sortByAuthorLastNameDesc",
        },
        {
          name: "Format",
          span: 1,
          clickHandler: this.toggleSortByFormat,
          ascending: "sortByFormat",
          descending: "sortByFormatDesc",
        },
        {
          name: "Genres",
          span: 3,
          clickHandler: null,
          ascending: null,
          descending: null,
        },
        {
          name: "Date Read",
          span: 2,
          clickHandler: this.toggleSortByDateCompleted,
          ascending: "sortByDateCompleted",
          descending: "sortByDateCompletedDesc",
        },
        {
          name: "Rating",
          span: 1,
          clickHandler: this.toggleSortByRating,
          ascending: "sortByRating",
          descending: "sortByRatingDesc",
        },
      ],
    };
  },
  computed: {
    sortedByValue() {
      return this.BooksStore.sortedBy;
    },
    arrowPosition() {
      return (ascending, descending) => {
        if (this.sortedByValue === ascending) {
          return "up";
        } else if (this.sortedByValue === descending) {
          return "down";
        } else {
          return null;
        }
      };
    },
  },
  methods: {
    // This shouldn't "set All Books" going forward. It should only set the bookshelf
    // For now, these are the same thing though
    toggleSort(sortBy, ascending, descending, defaultSort) {
      switch (this.sortedByValue) {
        case ascending:
          this.BooksStore.setAllBooks(this.BooksStore[descending](this.books));
          break;
        case descending:
          this.BooksStore.setAllBooks(this.BooksStore[defaultSort](this.books));
          break;
        default:
          this.BooksStore.setAllBooks(this.BooksStore[ascending](this.books));
          break;
      }
    },
    toggleSortByTitle() {
      this.toggleSort(
        "title",
        "sortByTitleAlpha",
        "sortByTitleAlphaDesc",
        "setToDefault"
      );
    },
    toggleSortByAuthor() {
      this.toggleSort(
        "author",
        "sortByAuthorLastName",
        "sortByAuthorLastNameDesc",
        "setToDefault"
      );
    },
    toggleSortByFormat() {
      this.toggleSort(
        "format",
        "sortByFormat",
        "sortByFormatDesc",
        "setToDefault"
      );
    },
    toggleSortByDateCompleted() {
      this.toggleSort(
        "date_completed",
        "sortByDateCompleted",
        "sortByDateCompletedDesc",
        "setToDefault"
      );
    },
    toggleSortByRating() {
      this.toggleSort(
        "rating",
        "sortByRating",
        "sortByRatingDesc",
        "setToDefault"
      );
    },
  },
};
</script>

<style>
.arrow-down {
  transform: rotate(180deg);
}
</style>

