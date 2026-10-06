import { describe, it, expect, beforeEach } from "vitest";
import { setActivePinia, createPinia } from "pinia";
import useBooksStore from "@/stores/BooksStore";

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
    // replaceVersion
    // ------------------------
    it("should merge the updated version into the cached book", () => {
        store.addBook({
            book: { book_id: 1 },
            versions: [
                { version_id: 1, nickname: "Hardback", is_discarded: false },
                { version_id: 2, nickname: "Audio", is_discarded: false },
            ],
        });

        store.replaceVersion(1, {
            version_id: 2,
            is_discarded: true,
            discarded_at: null,
        });

        expect(store.allBooks[0].versions[1]).toEqual({
            version_id: 2,
            nickname: "Audio",
            is_discarded: true,
            discarded_at: null,
        });
        expect(store.allBooks[0].versions[0].is_discarded).toBe(false);
    });

    it("should ignore a version replacement for an unknown book or version", () => {
        const book = {
            book: { book_id: 1 },
            versions: [{ version_id: 1, is_discarded: false }],
        };
        store.addBook(book);

        store.replaceVersion(99, { version_id: 1, is_discarded: true });
        store.replaceVersion(1, { version_id: 99, is_discarded: true });

        expect(store.allBooks[0].versions[0].is_discarded).toBe(false);
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
    // Ordering
    // ------------------------
    // The store holds one page of a server-ordered listing, so it preserves
    // the order it was given rather than imposing one. Sorting is a query
    // parameter — see LibrarySortingTest.php.
    it("should preserve the order books were set in", () => {
        const books = [
            { book: { book_id: 2, title: "Z Book" }, versions: [] },
            { book: { book_id: 1, title: "A Book" }, versions: [] },
        ];
        store.setAllBooks(books);

        expect(store.allBooks).toEqual(books);
    });

    // ------------------------
    // replaceAuthor
    // ------------------------
    it("replaces a renamed author on every cached book that credits them", () => {
        const other = { author_id: 5, first_name: "Neil", last_name: "Gaiman" };
        store.setAllBooks([
            {
                book: { book_id: 1 },
                authors: [{ author_id: 1, last_name: "Prachett" }, other],
            },
            { book: { book_id: 2 }, authors: [other] },
        ]);

        store.replaceAuthor({
            author_id: 1,
            first_name: "Terry",
            last_name: "Pratchett",
            slug: "terry-pratchett",
        });

        expect(store.allBooks[0].authors).toEqual([
            {
                author_id: 1,
                first_name: "Terry",
                last_name: "Pratchett",
                slug: "terry-pratchett",
            },
            other,
        ]);
        expect(store.allBooks[1].authors).toEqual([other]);
    });

    it("re-points merged-away authors and keeps one entry per book", () => {
        store.setAllBooks([
            {
                book: { book_id: 1 },
                authors: [
                    { author_id: 2, last_name: "Prachett" },
                    { author_id: 1, last_name: "Pratchett" },
                ],
            },
        ]);

        store.replaceAuthor(
            {
                author_id: 1,
                first_name: "Terry",
                last_name: "Pratchett",
                slug: "tp",
            },
            [2],
        );

        expect(store.allBooks[0].authors).toEqual([
            {
                author_id: 1,
                first_name: "Terry",
                last_name: "Pratchett",
                slug: "tp",
            },
        ]);
    });

    it("leaves books without authors alone", () => {
        store.setAllBooks([{ book: { book_id: 1 }, versions: [] }]);
        store.replaceAuthor({ author_id: 1 });
        expect(store.allBooks[0]).toEqual({
            book: { book_id: 1 },
            versions: [],
        });
    });

    // ------------------------
    // addGenres
    // ------------------------
    it("adds genres to the named cached books, in name order", () => {
        const fantasy = { genre_id: 1, name: "fantasy" };
        const essays = { genre_id: 2, name: "essays" };
        store.setAllBooks([
            { book: { book_id: 1 }, genres: [fantasy] },
            { book: { book_id: 2 }, genres: [] },
        ]);

        store.addGenres([1], [essays]);

        expect(store.allBooks[0].genres).toEqual([essays, fantasy]);
        expect(store.allBooks[1].genres).toEqual([]);
    });

    it("doesn't duplicate a genre the book already holds", () => {
        const fantasy = { genre_id: 1, name: "fantasy" };
        const entry = { book: { book_id: 1 }, genres: [fantasy] };
        store.setAllBooks([entry]);

        store.addGenres([1], [fantasy]);

        expect(store.allBooks[0]).toEqual(entry);
    });

    it("handles a cached book with no genres key", () => {
        const fantasy = { genre_id: 1, name: "fantasy" };
        store.setAllBooks([{ book: { book_id: 1 }, versions: [] }]);

        store.addGenres([1], [fantasy]);

        expect(store.allBooks[0].genres).toEqual([fantasy]);
    });
});
