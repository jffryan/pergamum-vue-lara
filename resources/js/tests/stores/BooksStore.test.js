import { describe, it, expect, beforeEach, vi } from "vitest";
import { setActivePinia, createPinia } from "pinia";
import useBooksStore from "@/stores/BooksStore";
import { addVersionToBookService } from "@/services/BookServices";

// Mock the API call
vi.mock("@/services/BookServices", () => ({
    addVersionToBookService: vi.fn(),
}));

describe("BooksStore", () => {
    let store;

    beforeEach(() => {
        setActivePinia(createPinia());
        store = useBooksStore();
    });

    // ------------------------
    // Initial State
    // ------------------------
    it("should have the correct initial state", () => {
        expect(store.allBooks).toEqual([]);
        expect(store.sortedBy).toBe("default");
    });

    // ------------------------
    // setAllBooks
    // ------------------------
    it("should set all books correctly", () => {
        const books = [{ book: { book_id: 1 }, versions: [] }];
        store.setAllBooks(books);
        expect(store.allBooks).toEqual(books);
    });

    // ------------------------
    // addBook
    // ------------------------
    it("should add a book when it does not exist", () => {
        const book = { book: { book_id: 1 }, versions: [] };
        store.addBook(book);
        expect(store.allBooks).toContainEqual(book);
    });

    it("should merge versions when adding an existing book", () => {
        const book1 = { book: { book_id: 1 }, versions: [{ version_id: 1 }] };
        const book2 = { book: { book_id: 1 }, versions: [{ version_id: 2 }] };

        store.addBook(book1);
        store.addBook(book2);

        expect(store.allBooks.length).toBe(1);
        expect(store.allBooks[0].versions).toEqual([
            { version_id: 1 },
            { version_id: 2 },
        ]);
    });

    it("should not duplicate versions when adding an existing book", () => {
        const book1 = {
            book: { book_id: 1 },
            versions: [{ version_id: 1 }, { version_id: 2 }],
        };
        const book2 = {
            book: { book_id: 1 },
            versions: [{ version_id: 2 }, { version_id: 3 }],
        };

        store.addBook(book1);
        store.addBook(book2);

        expect(store.allBooks[0].versions).toEqual([
            { version_id: 1 },
            { version_id: 2 },
            { version_id: 3 },
        ]);
    });

    // ------------------------
    // addVersionToBook
    // ------------------------
    it("should add a version to an existing book and call API", async () => {
        const book = { book: { book_id: 1 }, versions: [] };
        store.addBook(book);

        const version = { version_id: 101 };
        await store.addVersionToBook(1, version);

        expect(store.allBooks[0].versions).toContainEqual(version);
        expect(addVersionToBookService).toHaveBeenCalledWith(1, version);
    });

    it("should handle error when adding a version to a non-existent book", async () => {
        console.error = vi.fn();

        const version = { version_id: 101 };
        await store.addVersionToBook(99, version); // Book ID 99 doesn't exist

        expect(console.error).toHaveBeenCalledWith(
            "Failed to add version:",
            new Error("Book not found"),
        );
    });

    // ------------------------
    // updateBook
    // ------------------------
    it("should update an existing book", () => {
        const book = { book: { book_id: 1 }, versions: [] };
        store.addBook(book);

        const updatedBook = {
            book: { book_id: 1 },
            versions: [{ version_id: 3 }],
        };
        store.updateBook(updatedBook);

        expect(store.allBooks[0]).toEqual(updatedBook);
    });

    it("should not update if book does not exist", () => {
        const book = { book: { book_id: 1 }, versions: [] };
        store.updateBook(book);
        expect(store.allBooks).toHaveLength(0); // No book should be added
    });

    // ------------------------
    // deleteBook
    // ------------------------
    it("should delete a book by book_id", () => {
        const book = { book: { book_id: 1 }, versions: [] };
        store.addBook(book);
        store.deleteBook(book);
        expect(store.allBooks).toEqual([]);
    });

    it("should not delete a book if it does not exist", () => {
        const book = { book: { book_id: 1 }, versions: [] };
        store.addBook(book);
        store.deleteBook({ book: { book_id: 2 } }); // Book ID 2 doesn't exist
        expect(store.allBooks).toEqual([book]);
    });

    // ------------------------
    // sortedBooks Getter
    // ------------------------
    it("should return books sorted by title when sortedBy is not default", () => {
        store.setAllBooks([
            { book: { book_id: 2, title: "Z Book" }, versions: [] },
            { book: { book_id: 1, title: "A Book" }, versions: [] },
        ]);

        store.sortedBy = "title"; // Changing sort criteria

        expect(store.sortedBooks).toEqual([
            { book: { book_id: 1, title: "A Book" }, versions: [] },
            { book: { book_id: 2, title: "Z Book" }, versions: [] },
        ]);
    });

    it("should return books in default order if sortedBy is default", () => {
        const books = [
            { book: { book_id: 1, title: "A Book" }, versions: [] },
            { book: { book_id: 2, title: "Z Book" }, versions: [] },
        ];
        store.setAllBooks(books);

        expect(store.sortedBooks).toEqual(books);
    });
});
