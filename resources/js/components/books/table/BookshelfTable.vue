<template>
  <div>
    <h3 class="capitalize">{{ bookshelfTitle }}</h3>
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
      <Sortable
        v-if="isSortable"
        :list="books"
        item-key="book_id"
        @end="moveItemInArray(books, $event.oldIndex, $event.newIndex)"
      >
        <template #item="{ element, index }">
          <BookTableRow
            :key="element.book_id"
            :book="element"
            :class="[
              index % 2 === 0 ? 'bg-slate-200' : 'bg-slate-300',
              ' text-black cursor-pointer hover:bg-slate-600 hover:text-white',
            ]"
          />
        </template>
      </Sortable>
      <div v-else>
        <BookTableRow
          v-for="(book, index) in books"
          :key="book.book_id"
          :book="book"
          :class="[
            index % 2 === 0 ? 'bg-slate-100' : 'bg-slate-200',
            ' text-black cursor-pointer hover:bg-slate-500 hover:text-white',
          ]"
        />
      </div>
    </div>
  </div>
</template>

<script>
import { Sortable } from "sortablejs-vue3";

import { useBooksStore } from "@/stores";

import BookTableRow from "@/components/books/table/BookTableRow.vue";
import UpArrow from "@/components/globals/svgs/UpArrow.vue";

export default {
  name: "BookshelfTable",
  components: {
    BookTableRow,
    UpArrow,
    Sortable,
  },
  props: {
    books: {
      type: Array,
      required: true,
    },
    bookshelfTitle: {
      type: String,
      required: false,
      default: "All Books",
    },
    isSortable: {
      type: Boolean,
      required: false,
      default: false,
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
          ascending: "sortByTitleAlpha",
          descending: "sortByTitleAlphaDesc",
        },
        {
          name: "Primary Author",
          span: 2,
          ascending: "sortByAuthorLastName",
          descending: "sortByAuthorLastNameDesc",
        },
        {
          name: "Format",
          span: 1,
          ascending: "sortByFormat",
          descending: "sortByFormatDesc",
        },
        {
          name: "Page Count",
          span: 1,
          ascending: null,
          descending: null,
        },
        {
          name: "Genres",
          span: 3,
          ascending: null,
          descending: null,
        },
        {
          name: "Date Read",
          span: 1,
          ascending: "sortByDateCompleted",
          descending: "sortByDateCompletedDesc",
        },
        {
          name: "Rating",
          span: 1,
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
    // This works but makes the component messy for now.
    moveItemInArray(array, oldIndex, newIndex) {
      array.splice(newIndex, 0, array.splice(oldIndex, 1)[0]);
    },
  },
};
</script>

<style>
.arrow-down {
  transform: rotate(180deg);
}
</style>
