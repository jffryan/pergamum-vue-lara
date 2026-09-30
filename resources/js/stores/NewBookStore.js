import { defineStore } from "pinia";

/**
 * The existing book an add-a-copy or add-a-read page is working on.
 *
 * This used to be the new-book flow's step machine as well — title, then
 * authors, then genres, then a copy, each step pushing into
 * `currentBookData` and choosing the next component. The new-book page is
 * now one form holding its own draft (`utils/newBookForm.js`), so what's left
 * is the existing-book half. The name is a leftover; renaming it is tracked in
 * `/feature-plans/new-book-creation.md`.
 */
function initializeBookData() {
    return {
        book: {
            book_id: null,
            title: "",
            slug: "",
        },
        authors: [],
        genres: [],
        read_instances: [],
        versions: [],
    };
}

const useNewBookStore = defineStore("NewBookStore", {
    state: () => ({
        currentBookData: initializeBookData(),
    }),
    actions: {
        resetStore() {
            this.currentBookData = initializeBookData();
        },
        setBookFromExisting(book) {
            this.currentBookData = {
                book: {
                    book_id: book.book_id,
                    title: book.title,
                    slug: book.slug,
                },
                authors: book.authors,
                genres: book.genres,
                read_instances: book.read_instances ?? [], // Preserve existing read instances
                versions: book.versions,
            };
        },
        addReadInstanceToExistingBookVersion(readInstance, selectedVersion) {
            if (!readInstance || !selectedVersion) return;

            const formattedReadInstance = {
                ...readInstance,
                version_id: selectedVersion.version_id,
                book_id: selectedVersion.book_id,
            };

            this.currentBookData.read_instances.push(formattedReadInstance);
        },
    },
});

export default useNewBookStore;
