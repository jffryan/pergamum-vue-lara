import { defineStore } from "pinia";

export const useBooksStore = defineStore("BooksStore", {
  state: () => ({
    allBooks: [],
    sortedBy: "default",
  }),
  actions: {
    // ---------------------
    // Set books
    // ---------------------
    setAllBooks(books) {
      this.allBooks = books;
    },
    // ---------------------
    // Sort helper functions
    // ---------------------
    sortBooks(books, key, nestedKey) {
      return books.sort((a, b) => {
        // If we have a nested key, use it. Otherwise just use the book key.
        const valueA = nestedKey ? a[key][0][nestedKey] : a[key];
        const valueB = nestedKey ? b[key][0][nestedKey] : b[key];
        if (valueA < valueB) return -1;
        if (valueA > valueB) return 1;
        return 0;
      });
    },
    setAndReturnSortedBooks(books, sortValue) {
      // Does what it says. Saves repetition.
      this.sortedBy = sortValue;
      return books;
    },
    setToDefault(books) {
      this.sortBooks(books, "book_id");
      return this.setAndReturnSortedBooks(books, "default");
    },
    // ---------------------
    // Sort functions built through helpers
    // ---------------------
    // Need a way to eliminate null values from the sort
    sortByTitleAlpha(books) {
      this.sortBooks(books, "title");
      return this.setAndReturnSortedBooks(books, "sortByTitleAlpha");
    },
    sortByTitleAlphaDesc(books) {
      const sortedBooks = this.sortByTitleAlpha(books).reverse();
      return this.setAndReturnSortedBooks(sortedBooks, "sortByTitleAlphaDesc");
    },
    sortByAuthorLastName(books) {
      this.sortBooks(books, "authors", "last_name");
      return this.setAndReturnSortedBooks(books, "sortByAuthorLastName");
    },
    sortByAuthorLastNameDesc(books) {
      const sortedBooks = this.sortByAuthorLastName(books).reverse();
      return this.setAndReturnSortedBooks(
        sortedBooks,
        "sortByAuthorLastNameDesc"
      );
    },
    sortByFormat(books) {
      this.sortBooks(books, "versions", "format_id");
      return this.setAndReturnSortedBooks(books, "sortByFormat");
    },
    sortByFormatDesc(books) {
      const sortedBooks = this.sortByFormat(books).reverse();
      return this.setAndReturnSortedBooks(sortedBooks, "sortByFormatDesc");
    },
    sortByRating(books) {
      const sortedBooks = this.sortByRatingDesc(books).reverse();
      return this.setAndReturnSortedBooks(sortedBooks, "sortByRating");
    },
    sortByRatingDesc(books) {
      this.sortBooks(books, "rating");
      return this.setAndReturnSortedBooks(books, "sortByRatingDesc");
    },
    sortByDateCompleted(books) {
      this.sortBooks(books, "date_completed");
      return this.setAndReturnSortedBooks(books, "sortByDateCompleted");
    },
    sortByDateCompletedDesc(books) {
      const sortedBooks = this.sortByDateCompleted(books).reverse();
      return this.setAndReturnSortedBooks(
        sortedBooks,
        "sortByDateCompletedDesc"
      );
    },
  },
});

