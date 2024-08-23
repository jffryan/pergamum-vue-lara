import { defineStore } from "pinia";

const useBooksStore = defineStore("BooksStore", {
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
        addBook(book) {
            const existingBookIndex = this.allBooks.findIndex(
                (b) => b.book.book_id === book.book.book_id,
            );

            // If book already exists in the state
            if (existingBookIndex !== -1) {
                // Merge the new versions with the existing book's versions
                this.allBooks[existingBookIndex].versions = [
                    ...this.allBooks[existingBookIndex].versions,
                    ...book.versions,
                ];
            } else {
                // If the book is completely new
                this.allBooks.push(book);
            }
        },
        // Updates existing book in allBooks array
        updateBook(book) {
            const index = this.allBooks.findIndex(
                (b) => b.book.book_id === book.book.book_id,
            );
            this.allBooks[index] = book;
        },
        deleteBook(book) {
            const index = this.allBooks.findIndex(
                (b) => b.book_id === book.book_id,
            );
            this.allBooks.splice(index, 1);
        },
    },
});

export default useBooksStore;
