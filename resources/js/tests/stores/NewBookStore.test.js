import { describe, it, expect, beforeEach } from "vitest";
import { setActivePinia, createPinia } from "pinia";
import useNewBookStore from "@/stores/NewBookStore";

// The create flow's steps left this store when the new-book page became one
// form; its draft logic is covered by `tests/utils/newBookForm.test.js`. What
// remains is the existing-book half used by the add-copy and add-read pages.
describe("NewBookStore", () => {
    let store;

    beforeEach(() => {
        setActivePinia(createPinia());
        store = useNewBookStore();
    });

    it("should have the correct initial state", () => {
        expect(store.currentBookData).toEqual({
            book: { book_id: null, title: "", slug: "" },
            authors: [],
            genres: [],
            read_instances: [],
            versions: [],
        });
    });

    it("should reset the store correctly", () => {
        store.currentBookData.book.title = "Modified Title";
        store.resetStore();

        expect(store.currentBookData.book.title).toBe("");
    });

    it("should load an existing book, keeping its read history", () => {
        const reads = [{ read_instance_id: 4, version_id: 1 }];

        store.setBookFromExisting({
            book_id: 7,
            title: "Dune",
            slug: "dune",
            authors: [{ author_id: 1 }],
            genres: [],
            read_instances: reads,
            versions: [{ version_id: 1 }],
        });

        expect(store.currentBookData.book).toEqual({
            book_id: 7,
            title: "Dune",
            slug: "dune",
        });
        expect(store.currentBookData.read_instances).toEqual(reads);
        expect(store.currentBookData.versions).toEqual([{ version_id: 1 }]);
    });

    it("should default read history to empty when the book carries none", () => {
        store.setBookFromExisting({
            book_id: 7,
            title: "Dune",
            slug: "dune",
            authors: [],
            genres: [],
            versions: [],
        });

        expect(store.currentBookData.read_instances).toEqual([]);
    });

    it("should add a read instance to an existing version", () => {
        const readInstance = { date_read: "2024-01-01" };
        const version = { version_id: 1, book_id: 2 };

        store.addReadInstanceToExistingBookVersion(readInstance, version);
        expect(store.currentBookData.read_instances).toContainEqual({
            ...readInstance,
            version_id: 1,
            book_id: 2,
        });
    });

    it("should ignore a read instance with no version", () => {
        store.addReadInstanceToExistingBookVersion({ date_read: "2024-01-01" });

        expect(store.currentBookData.read_instances).toEqual([]);
    });
});
