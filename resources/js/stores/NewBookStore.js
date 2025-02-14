import { defineStore } from "pinia";
import { createOrGetBookByTitle, submitNewBook } from "@/api/BookController";

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
        addToBacklog: false,
    };
}

function initializeFirstStep() {
    return {
        heading: "New Book",
        component: ["NewBookTitleInput"],
    };
}

const useNewBookStore = defineStore("NewBookStore", {
    state: () => ({
        currentBookData: initializeBookData(),
        currentStep: initializeFirstStep(),
    }),
    actions: {
        // Store lifecycle and reset
        resetStore() {
            this.currentBookData = initializeBookData();
            this.currentStep = initializeFirstStep();
        },
        setStep(components, heading = null) {
            this.currentStep.component = components;
            if (heading) this.currentStep.heading = heading;
        },
        // Book creation workflow
        async beginBookCreation(bookData) {
            this.resetStore();
            // Check to ensure title exists on the request
            if (!bookData.title) return;
            this.currentBookData.book.title = bookData.title;
            // Check if a book with this title already exists
            const response = await createOrGetBookByTitle(bookData.title);
            // Cheeky
            const res = response.data;
            if (res.exists) {
                this.setBookFromExisting(res.book);
                this.setStep([
                    "NewBookVersionConfirmation",
                    "NewBookProgressForm",
                ]);
            } else {
                this.currentBookData.book.slug = res.book.slug;
                this.setStep(["NewAuthorsInput", "NewBookProgressForm"]);
            }
        },
        resetToAuthors() {
            const { title } = this.currentBookData.book;
            this.resetStore();
            this.currentBookData.book.title = title;
            // Set bookslug to a random string to avoid conflicts with existing books
            this.currentBookData.book.slug = Math.random()
                .toString(36)
                .substring(7);
            this.setStep(["NewAuthorsInput", "NewBookProgressForm"]);
        },
        // Book Data Updates
        updateBookField(field, value) {
            if (
                Object.prototype.hasOwnProperty.call(
                    this.currentBookData.book,
                    field,
                )
            ) {
                this.currentBookData.book[field] = value;
            }
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
                addToBacklog: false,
            };
            this.setStep(
                ["NewBookProgressForm", "NewBookSubmitControls"],
                "Select an option",
            );
        },
        // Adding book attributes
        addAuthorsToNewBook(authors) {
            if (!authors.length) return;
            this.currentBookData.authors = authors.map((a) => ({
                name: `${a.first_name} ${a.last_name}`,
                first_name: a.first_name,
                last_name: a.last_name,
                author_id: null,
            }));
            this.setStep(["NewGenresInput", "NewBookProgressForm"]);
        },
        addGenresToNewBook(genres) {
            if (!genres.length) return;
            this.currentBookData.genres = genres.map((name) => ({
                name,
                genre_id: null,
            }));
            this.setStep(["NewVersionsInput", "NewBookProgressForm"]);
        },
        addVersionToNewBook(version) {
            if (!version) return;

            this.currentBookData.versions.push({
                audio_runtime: version.audio_runtime,
                format: version.format,
                nickname: version.nickname,
                page_count: version.page_count,
                read_instances: [],
                version_id: null,
            });

            this.setStep(
                version.is_read
                    ? ["NewReadInstanceInput", "NewBookProgressForm"]
                    : ["NewBacklogItemInput", "NewBookProgressForm"],
            );
        },
        // Read Instance Management
        addReadInstanceToNewBookVersion(readInstance) {
            if (!readInstance) return;

            if (!this.currentBookData.versions.length) {
                console.error("No versions exist to attach a read instance.");
                return;
            }

            const formattedReadInstance = {
                read_instances_id: null,
                date_read: readInstance.date_read,
            };

            const lastVersion = this.currentBookData.versions.at(-1);
            lastVersion.read_instances.push(formattedReadInstance);
            this.currentBookData.read_instances.push(formattedReadInstance);

            this.setStep(
                ["NewBookProgressForm", "NewBookSubmitControls"],
                "Review book details",
            );
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
        setBacklogItemToNewBook(backlogItem) {
            if (!backlogItem) {
                this.setStep(
                    ["NewBookSubmitControls", "NewBookProgressForm"],
                    "Review book details",
                );
                return;
            }

            if (!this.currentBookData.addToBacklog) {
                this.currentBookData.addToBacklog = true;
            }

            this.setStep(
                ["NewBookSubmitControls", "NewBookProgressForm"],
                "Review book details",
            );
        },
        // Submitting the new book
        async submitNewBook() {
            try {
                return await submitNewBook(this.currentBookData);
            } catch (error) {
                console.error("Failed to submit new book:", error);
                return { success: false, error };
            }
        },
    },
});

export default useNewBookStore;
