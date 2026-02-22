import { defineStore } from "pinia";
import {
    addBookToBacklogService,
    addVersionToBookService,
    removeBookFromBacklogService,
} from "@/services/BookServices";

const useBooksStore = defineStore("BooksStore", {
    state: () => ({
        allBooks: [],
        sortedBy: "default",
    }),
    getters: {
        sortedBooks(state) {
            if (state.sortedBy === "default") return state.allBooks;
            return [...state.allBooks].sort((a, b) =>
                a.book.title.localeCompare(b.book.title),
            );
        },
    },
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
        async removeBookFromBacklog(bookId) {
            try {
                const index = this.allBooks.findIndex(
                    (b) => b.book.book_id === bookId,
                );
                if (index === -1) throw new Error("Book not found");

                const backlogItemId =
                    this.allBooks[index].backlogItem?.backlog_item_id;
                if (!backlogItemId) throw new Error("Book not in backlog");
                await removeBookFromBacklogService(backlogItemId);
                this.allBooks[index].backlogItem = null;
            } catch (error) {
                console.error("Failed to remove book from backlog", error);
            }
        },
        async addBookToBacklog(bookId) {
            try {
                const index = this.allBooks.findIndex(
                    (b) => b.book.book_id === bookId,
                );
                if (index === -1) throw new Error("Book not found");
                const response = await addBookToBacklogService(bookId);
                this.allBooks[index].backlogItem = response.data;
            } catch (error) {
                console.error("Failed to add book to backlog", error);
            }
        },
    },
});

export default useBooksStore;
