import { defineStore } from "pinia";
import {
    getAllAuthors,
    updateAuthor as updateAuthorRequest,
    mergeAuthors as mergeAuthorsRequest,
} from "@/api/AuthorController";
import useBooksStore from "./BooksStore";

const useAuthorsStore = defineStore("AuthorsStore", {
    state: () => ({
        allAuthors: [],
        currentAuthor: {},
    }),
    actions: {
        setCurrentAuthor(author) {
            this.currentAuthor = author;
        },
        setAllAuthors(authors) {
            this.allAuthors = authors;
        },
        async fetchAllAuthors({ force = false } = {}) {
            if (this.allAuthors.length && !force) {
                return this.allAuthors;
            }

            const response = await getAllAuthors();
            this.setAllAuthors(response.data);

            return this.allAuthors;
        },
        // Mutations refetch rather than patch `allAuthors`, for `GenreStore`'s
        // reason: a merge changes `books_count` on a row it never named. They
        // also push the result into the books already cached in `BooksStore`,
        // whose rows carry their own copy of each author — without that, a
        // book opened earlier in the session would keep the old name and link
        // to the old slug until a reload.
        //
        // Neither catches. A rename onto a taken name is a 409 carrying the
        // other author, and the caller needs it to offer the merge.
        async renameAuthor(author_id, names) {
            const response = await updateAuthorRequest(author_id, names);
            useBooksStore().replaceAuthor(response.data);
            await this.fetchAllAuthors({ force: true });

            return response.data;
        },
        async mergeAuthors(keep_id, source_ids) {
            const response = await mergeAuthorsRequest(keep_id, source_ids);
            useBooksStore().replaceAuthor(response.data, source_ids);
            await this.fetchAllAuthors({ force: true });

            return response.data;
        },
    },
});

export default useAuthorsStore;
