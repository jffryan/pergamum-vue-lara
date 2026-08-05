import { defineStore } from "pinia";
import {
    getAllGenres,
    createGenre as createGenreRequest,
    updateGenre as updateGenreRequest,
    deleteGenre as deleteGenreRequest,
    mergeGenres as mergeGenresRequest,
} from "@/api/GenresController";

const useGenreStore = defineStore("GenreStore", {
    state: () => ({
        allGenres: [],
        currentPage: 1,
    }),
    actions: {
        setAllGenres(genres) {
            this.allGenres = genres;
        },
        async fetchAllGenres({ force = false } = {}) {
            if (this.allGenres.length && !force) {
                return this.allGenres;
            }

            const response = await getAllGenres();
            this.setAllGenres(response.data);

            return this.allGenres;
        },
        // Every mutation below refetches rather than patching `allGenres` in
        // place, for the same reason `ConfigStore.createFormat` does: the index
        // payload carries `books_count`, which a merge or a delete changes for
        // rows the mutation never named. This array is also what `GenresView`
        // and `GenreTagInput` read, so after a merge a patched-in-place list
        // would keep handing out entries that point at deleted rows.
        //
        // None of them catch. A create or rename that collides is a 409 whose
        // body carries the conflicting genre, and the caller needs that
        // payload to offer "merge into it instead" — swallowing it here would
        // throw away the only copy of the id.
        async createGenre(name) {
            const response = await createGenreRequest(name);
            await this.fetchAllGenres({ force: true });

            return response.data;
        },
        async renameGenre(genre_id, name) {
            const response = await updateGenreRequest(genre_id, name);
            await this.fetchAllGenres({ force: true });

            return response.data;
        },
        async deleteGenre(genre_id, { force = false } = {}) {
            const response = await deleteGenreRequest(genre_id, { force });
            await this.fetchAllGenres({ force: true });

            return response.data;
        },
        async mergeGenres(keep_id, source_ids) {
            const response = await mergeGenresRequest(keep_id, source_ids);
            await this.fetchAllGenres({ force: true });

            return response.data;
        },
    },
});

export default useGenreStore;
