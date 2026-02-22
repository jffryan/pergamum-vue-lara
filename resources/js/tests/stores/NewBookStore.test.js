import { describe, it, expect, beforeEach, vi } from "vitest";
import { setActivePinia, createPinia } from "pinia";
import useNewBookStore from "@/stores/NewBookStore";
import { createOrGetBookByTitle, submitNewBook } from "@/api/BookController";

// Mock API calls
vi.mock("@/api/BookController", () => ({
    createOrGetBookByTitle: vi.fn(),
    submitNewBook: vi.fn(),
}));

describe("NewBookStore", () => {
    let store;

    beforeEach(() => {
        setActivePinia(createPinia());
        store = useNewBookStore();
    });

    // ----------------------------
    // STATE INITIALIZATION
    // ----------------------------
    it("should have the correct initial state", () => {
        expect(store.currentBookData).toEqual({
            book: { book_id: null, title: "", slug: "" },
            authors: [],
            genres: [],
            read_instances: [],
            versions: [],
        });

        expect(store.currentStep).toEqual({
            heading: "New Book",
            component: ["NewBookTitleInput"],
        });
    });

    // ----------------------------
    // STORE RESET
    // ----------------------------
    it("should reset the store correctly", () => {
        store.currentBookData.book.title = "Modified Title";
        store.resetStore();

        expect(store.currentBookData.book.title).toBe("");
        expect(store.currentStep.heading).toBe("New Book");
    });

    it("should update currentStep correctly using setStep", () => {
        store.setStep(["Step1", "Step2"], "New Heading");

        expect(store.currentStep.component).toEqual(["Step1", "Step2"]);
        expect(store.currentStep.heading).toBe("New Heading");
    });

    it("should allow setting steps without a heading", () => {
        store.setStep(["OnlyComponent"]);

        expect(store.currentStep.component).toEqual(["OnlyComponent"]);
        expect(store.currentStep.heading).toBe("New Book"); // Should remain unchanged
    });

    // ----------------------------
    // BOOK CREATION WORKFLOW
    // ----------------------------
    it("should not proceed with book creation if title is missing", async () => {
        await store.beginBookCreation({});
        expect(store.currentBookData.book.title).toBe("");
    });

    it("should handle creating a new book when it does not exist", async () => {
        createOrGetBookByTitle.mockResolvedValue({
            data: { exists: false, book: { slug: "new-slug" } },
        });

        await store.beginBookCreation({ title: "New Book" });

        expect(store.currentBookData.book.title).toBe("New Book");
        expect(store.currentBookData.book.slug).toBe("new-slug");
        expect(store.currentStep.component).toEqual([
            "NewAuthorsInput",
            "NewBookProgressForm",
        ]);
    });

    it("should handle an existing book correctly", async () => {
        createOrGetBookByTitle.mockResolvedValue({
            data: {
                exists: true,
                book: {
                    book_id: 1,
                    title: "Existing Book",
                    slug: "existing-slug",
                },
            },
        });

        await store.beginBookCreation({ title: "Existing Book" });

        expect(store.currentBookData.book.title).toBe("Existing Book");
        expect(store.currentBookData.book.slug).toBe("existing-slug");
        expect(store.currentStep.component).toEqual([
            "NewBookVersionConfirmation",
            "NewBookProgressForm",
        ]);
    });

    // ----------------------------
    // BOOK DATA UPDATES
    // ----------------------------
    it("should update a specific book field correctly", () => {
        store.updateBookField("title", "Updated Title");
        expect(store.currentBookData.book.title).toBe("Updated Title");
    });

    it("should not update if the field does not exist", () => {
        store.updateBookField("nonexistent", "Test");
        expect(store.currentBookData.book.nonexistent).toBeUndefined();
    });

    // ----------------------------
    // ADDING BOOK ATTRIBUTES
    // ----------------------------
    it("should add authors correctly", () => {
        const authors = [{ first_name: "John", last_name: "Doe" }];
        store.addAuthorsToNewBook(authors);

        expect(store.currentBookData.authors).toEqual([
            {
                name: "John Doe",
                first_name: "John",
                last_name: "Doe",
                author_id: null,
            },
        ]);

        expect(store.currentStep.component).toEqual([
            "NewGenresInput",
            "NewBookProgressForm",
        ]);
    });

    it("should add genres correctly", () => {
        const genres = ["Fantasy", "Horror"];
        store.addGenresToNewBook(genres);

        expect(store.currentBookData.genres).toEqual([
            { name: "Fantasy", genre_id: null },
            { name: "Horror", genre_id: null },
        ]);

        expect(store.currentStep.component).toEqual([
            "NewVersionsInput",
            "NewBookProgressForm",
        ]);
    });

    it("should add versions correctly", () => {
        const version = {
            audio_runtime: 300,
            format: "Hardcover",
            nickname: "First Edition",
            page_count: 250,
            is_read: false,
        };
        store.addVersionToNewBook(version);

        expect(store.currentBookData.versions).toHaveLength(1);
        expect(store.currentStep.component).toEqual([
            "NewBookSubmitControls",
            "NewBookProgressForm",
        ]);
    });

    // ----------------------------
    // READ INSTANCE MANAGEMENT
    // ----------------------------
    it("should add a read instance to the latest book version", () => {
        store.currentBookData.versions.push({ read_instances: [] });

        const readInstance = { date_read: "2024-01-01" };
        store.addReadInstanceToNewBookVersion(readInstance);

        expect(store.currentBookData.versions[0].read_instances).toHaveLength(
            1,
        );
        expect(store.currentStep.component).toEqual([
            "NewBookProgressForm",
            "NewBookSubmitControls",
        ]);
    });

    it("should not add a read instance if no versions exist", () => {
        console.error = vi.fn();
        store.addReadInstanceToNewBookVersion({ date_read: "2024-01-01" });

        expect(console.error).toHaveBeenCalledWith(
            "No versions exist to attach a read instance.",
        );
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

    // ----------------------------
    // BOOK SUBMISSION
    // ----------------------------
    it("should successfully submit a book", async () => {
        submitNewBook.mockResolvedValue({ success: true });

        const response = await store.submitNewBook();
        expect(response).toEqual({ success: true });
    });

    it("should handle submission failure", async () => {
        console.error = vi.fn();
        submitNewBook.mockRejectedValue(new Error("API Error"));

        const response = await store.submitNewBook();
        expect(response.success).toBe(false);
        expect(console.error).toHaveBeenCalledWith(
            "Failed to submit new book:",
            expect.any(Error),
        );
    });
});
