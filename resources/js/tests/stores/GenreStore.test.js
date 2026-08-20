import { describe, it, expect, beforeEach, vi } from "vitest";
import { setActivePinia, createPinia } from "pinia";
import useGenreStore from "@/stores/GenreStore";
import {
    getAllGenres,
    createGenre,
    updateGenre,
    deleteGenre,
    mergeGenres,
} from "@/api/GenresController";

vi.mock("@/api/GenresController", () => ({
    getAllGenres: vi.fn(),
    getOneGenre: vi.fn(),
    createGenre: vi.fn(),
    updateGenre: vi.fn(),
    deleteGenre: vi.fn(),
    mergeGenres: vi.fn(),
}));

const essay = { genre_id: 1, name: "essay", books_count: 3 };
const essays = { genre_id: 2, name: "essays", books_count: 1 };

// The shape axios rejects with, which is what the components read.
const conflictError = () => {
    const error = new Error("Request failed with status code 409");
    error.response = {
        status: 409,
        data: {
            reason_code: "genre_name_taken",
            reason: "A genre named essay already exists.",
            conflict: essay,
        },
    };
    return error;
};

describe("GenreStore", () => {
    let store;

    beforeEach(() => {
        vi.clearAllMocks();
        setActivePinia(createPinia());
        store = useGenreStore();
    });

    // ------------------------
    // fetchAllGenres
    // ------------------------
    it("should have the correct initial state", () => {
        expect(store.allGenres).toEqual([]);
    });

    it("should populate allGenres from the API", async () => {
        getAllGenres.mockResolvedValue({ data: [essay, essays] });

        await store.fetchAllGenres();

        expect(store.allGenres).toEqual([essay, essays]);
    });

    it("should not refetch when the list is already cached", async () => {
        getAllGenres.mockResolvedValue({ data: [essay] });
        await store.fetchAllGenres();

        await store.fetchAllGenres();

        expect(getAllGenres).toHaveBeenCalledTimes(1);
    });

    it("should refetch a cached list when forced", async () => {
        getAllGenres.mockResolvedValue({ data: [essay] });
        await store.fetchAllGenres();

        await store.fetchAllGenres({ force: true });

        expect(getAllGenres).toHaveBeenCalledTimes(2);
    });

    // ------------------------
    // Mutations refetch rather than patch
    // ------------------------
    it("should refetch the list after creating a genre", async () => {
        getAllGenres.mockResolvedValue({ data: [essay, essays] });
        createGenre.mockResolvedValue({ data: essays });

        const created = await store.createGenre("essays");

        expect(createGenre).toHaveBeenCalledWith("essays");
        expect(getAllGenres).toHaveBeenCalledTimes(1);
        expect(created).toEqual(essays);
        expect(store.allGenres).toEqual([essay, essays]);
    });

    it("should refetch the list after renaming a genre", async () => {
        getAllGenres.mockResolvedValue({
            data: [{ ...essays, name: "memoir" }],
        });
        updateGenre.mockResolvedValue({ data: { ...essays, name: "memoir" } });

        await store.renameGenre(2, "memoir");

        expect(updateGenre).toHaveBeenCalledWith(2, "memoir");
        expect(getAllGenres).toHaveBeenCalledTimes(1);
        expect(store.allGenres).toEqual([{ ...essays, name: "memoir" }]);
    });

    it("should pass force through to the delete request and refetch", async () => {
        getAllGenres.mockResolvedValue({ data: [essay] });
        deleteGenre.mockResolvedValue({ data: { deleted: true } });

        await store.deleteGenre(2, { force: true });

        expect(deleteGenre).toHaveBeenCalledWith(2, { force: true });
        expect(getAllGenres).toHaveBeenCalledTimes(1);
    });

    it("should default the delete request to unforced", async () => {
        getAllGenres.mockResolvedValue({ data: [essay] });
        deleteGenre.mockResolvedValue({ data: { deleted: true } });

        await store.deleteGenre(2);

        expect(deleteGenre).toHaveBeenCalledWith(2, { force: false });
    });

    /**
     * The refetch is the whole point of this test, not an implementation
     * detail: a merge changes `books_count` on rows it never named and deletes
     * others outright, and this same array backs GenresView and
     * GenreTagInput's autocomplete. A patched-in-place list would keep handing
     * out entries pointing at deleted rows for the rest of the session.
     */
    it("should replace the cached list after a merge rather than patching it", async () => {
        getAllGenres.mockResolvedValueOnce({ data: [essay, essays] });
        await store.fetchAllGenres();

        getAllGenres.mockResolvedValueOnce({
            data: [{ ...essay, books_count: 4 }],
        });
        mergeGenres.mockResolvedValue({ data: { ...essay, books_count: 4 } });

        await store.mergeGenres(1, [2]);

        expect(mergeGenres).toHaveBeenCalledWith(1, [2]);
        expect(store.allGenres).toEqual([{ ...essay, books_count: 4 }]);
    });

    // ------------------------
    // Conflicts reach the caller
    // ------------------------
    it("should surface a create conflict to the caller rather than swallowing it", async () => {
        createGenre.mockRejectedValue(conflictError());

        await expect(store.createGenre("Essay")).rejects.toMatchObject({
            response: {
                status: 409,
                data: { reason_code: "genre_name_taken", conflict: essay },
            },
        });
        expect(getAllGenres).not.toHaveBeenCalled();
    });

    it("should surface a rename conflict to the caller rather than swallowing it", async () => {
        updateGenre.mockRejectedValue(conflictError());

        await expect(store.renameGenre(2, "Essay")).rejects.toMatchObject({
            response: { data: { conflict: { genre_id: 1 } } },
        });
        expect(getAllGenres).not.toHaveBeenCalled();
    });

    it("should surface an in-use delete refusal to the caller", async () => {
        const error = new Error("Request failed with status code 409");
        error.response = {
            status: 409,
            data: { reason_code: "genre_in_use", books_count: 14 },
        };
        deleteGenre.mockRejectedValue(error);

        await expect(store.deleteGenre(1)).rejects.toMatchObject({
            response: {
                data: { reason_code: "genre_in_use", books_count: 14 },
            },
        });
        expect(getAllGenres).not.toHaveBeenCalled();
    });
});
