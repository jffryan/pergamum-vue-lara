import { defineStore } from "pinia";

const useAuthorsStore = defineStore("AuthorsStore", {
    state: () => ({
        allAuthors: [],
        currentAuthor: {},
        sortedBy: "default",
    }),
    actions: {
        setCurrentAuthor(author) {
            this.currentAuthor = author;
        },
    },
});

export default useAuthorsStore;
