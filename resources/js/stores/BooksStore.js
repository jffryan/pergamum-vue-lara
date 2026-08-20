import { defineStore } from "pinia";
import { addVersionToBookService } from "@/services/BookServices";

const useBooksStore = defineStore("BooksStore", {
    // Ordering is not state here. The library listing is paginated, so its
    // sort is a query parameter resolved by the server — see
    // BookListing::SORTABLE. A client-side `sortedBy` could only ever
    // reorder the page already fetched.
    state: () => ({
        allBooks: [],
    }),
    actions: {
        setAllBooks(books) {
            this.allBooks = books;
        },
        addBook(book) {
            const existingBook = this.allBooks.find(
                (b) => b.book.book_id === book.book.book_id,
            );

            if (existingBook) {
                // Deduplicate versions
                const mergedVersions = new Map();
                [...existingBook.versions, ...book.versions].forEach((v) => {
                    mergedVersions.set(v.version_id, v);
                });
                existingBook.versions = Array.from(mergedVersions.values());
            } else {
                this.allBooks.push(book);
            }
        },
        async addVersionToBook(bookId, version) {
            try {
                const index = this.allBooks.findIndex(
                    (b) => b.book.book_id === bookId,
                );
                if (index === -1) throw new Error("Book not found");

                await addVersionToBookService(bookId, version);
                this.allBooks[index].versions.push(version);
            } catch (error) {
                console.error("Failed to add version:", error);
            }
        },
        replaceVersion(bookId, version) {
            const book = this.allBooks.find((b) => b.book.book_id === bookId);
            if (!book) return;

            const index = book.versions.findIndex(
                (v) => v.version_id === version.version_id,
            );
            if (index === -1) return;

            book.versions[index] = { ...book.versions[index], ...version };
        },
        updateBook(book) {
            const index = this.allBooks.findIndex(
                (b) => b.book.book_id === book.book.book_id,
            );
            this.allBooks[index] = book;
        },
        deleteBook(book) {
            this.allBooks = this.allBooks.filter(
                (b) => b.book.book_id !== book.book.book_id,
            );
        },
    },
});

export default useBooksStore;
