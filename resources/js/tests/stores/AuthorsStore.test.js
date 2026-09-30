import { describe, it, expect, beforeEach, vi } from "vitest";
import { setActivePinia, createPinia } from "pinia";
import useAuthorsStore from "@/stores/AuthorsStore";
import useBooksStore from "@/stores/BooksStore";
import {
    getAllAuthors,
    updateAuthor,
    mergeAuthors,
} from "@/api/AuthorController";

vi.mock("@/api/AuthorController", () => ({
    getAuthorBySlug: vi.fn(),
    getAllAuthors: vi.fn(),
    updateAuthor: vi.fn(),
    mergeAuthors: vi.fn(),
}));

const butler = {
    author_id: 1,
    first_name: "Octavia",
    last_name: "Butler",
    slug: "octavia-butler",
    books_count: 3,
};
const buttler = {
    author_id: 2,
    first_name: "Octavia",
    last_name: "Buttler",
    slug: "octavia-buttler",
    books_count: 1,
};

const conflictError = () => {
    const error = new Error("Request failed with status code 409");
    error.response = {
        status: 409,
        data: {
            reason_code: "author_name_taken",
            reason: "An author named Octavia Butler already exists.",
            conflict: butler,
        },
    };
    return error;
};

describe("AuthorsStore", () => {
    let store;

    beforeEach(() => {
        vi.clearAllMocks();
        setActivePinia(createPinia());
        store = useAuthorsStore();
    });

    it("fetches once and serves the cache after", async () => {
        getAllAuthors.mockResolvedValue({ data: [butler] });

        await store.fetchAllAuthors();
        await store.fetchAllAuthors();

        expect(getAllAuthors).toHaveBeenCalledTimes(1);
        expect(store.allAuthors).toEqual([butler]);
    });

    it("refetches when forced", async () => {
        getAllAuthors.mockResolvedValue({ data: [butler] });
        store.setAllAuthors([buttler]);

        await store.fetchAllAuthors({ force: true });

        expect(getAllAuthors).toHaveBeenCalledTimes(1);
        expect(store.allAuthors).toEqual([butler]);
    });

    it("renames, refetches, and updates cached books", async () => {
        const renamed = { ...buttler, last_name: "Butler-Smith", slug: "x" };
        updateAuthor.mockResolvedValue({ data: renamed });
        getAllAuthors.mockResolvedValue({ data: [butler, renamed] });
        const books = useBooksStore();
        books.setAllBooks([{ book: { book_id: 9 }, authors: [buttler] }]);

        const result = await store.renameAuthor(2, {
            first_name: "Octavia",
            last_name: "Butler-Smith",
        });

        expect(updateAuthor).toHaveBeenCalledWith(2, {
            first_name: "Octavia",
            last_name: "Butler-Smith",
        });
        expect(result).toEqual(renamed);
        expect(getAllAuthors).toHaveBeenCalledTimes(1);
        expect(books.allBooks[0].authors[0]).toMatchObject({
            last_name: "Butler-Smith",
            slug: "x",
        });
    });

    it("lets a name conflict reach the caller with its payload", async () => {
        updateAuthor.mockRejectedValue(conflictError());

        await expect(
            store.renameAuthor(2, {
                first_name: "Octavia",
                last_name: "Butler",
            }),
        ).rejects.toMatchObject({
            response: { data: { conflict: { author_id: 1 } } },
        });
        expect(getAllAuthors).not.toHaveBeenCalled();
    });

    it("merges, refetches, and re-points cached books", async () => {
        mergeAuthors.mockResolvedValue({ data: { ...butler, books_count: 4 } });
        getAllAuthors.mockResolvedValue({ data: [butler] });
        const books = useBooksStore();
        books.setAllBooks([{ book: { book_id: 9 }, authors: [buttler] }]);

        await store.mergeAuthors(1, [2]);

        expect(mergeAuthors).toHaveBeenCalledWith(1, [2]);
        expect(books.allBooks[0].authors.map((a) => a.author_id)).toEqual([1]);
    });
});
