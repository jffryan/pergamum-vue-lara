import { defineStore } from "pinia";
import axios from "axios";

function initializeBookData() {
  return {
    book: {
      book_id: null,
      title: "",
      slug: "",
      is_completed: false,
      rating: null,
    },
    authors: [
      {
        first_name: "",
        last_name: "",
      },
    ],
  };
}

function initializeFirstStep() {
  return {
    heading: "New Book",
    component: ["NewBookTitleInput"],
    status: null,
  };
}

const useNewBookStore = defineStore("NewBookStore", {
  state: () => ({
    currentBookData: initializeBookData(),
    currentStep: initializeFirstStep(),
  }),
  actions: {
    initializeBookData,
    initializeFirstStep,
    // ------------------------------
    // Methods that control the book creation process
    // ------------------------------
    async beginBookCreation(bookData) {
      // Reset current book data, just in case
      this.initializeBookData();
      // Check to ensure title exists on the request
      const { title } = bookData;
      if (!title) {
        return;
      }
      // Save it to state for later use
      this.setTitle(title);
      // Check if a book with this title already exists
      const res = await this.createOrGetBookByTitle(
        this.currentBookData.book.title
      );
      // If no prior model found, continue creating a new book
      if (!res.exists) {
        this.setSlug(res.book.slug);
        this.setCurrentStepComponent([
          "NewBookProgressForm",
          "NewAuthorsInput",
        ]);
      }
      // If a prior model found, set the book data from the existing record
      if (res.exists) {
        this.setBookFromExisting(res.book);
      }
    },
    // ------------------------------
    // Helper functions that set specific model data
    // ------------------------------
    setBookFromExisting(book) {
      this.setSlug(book.slug);
      this.setIsCompleted(book.is_completed);
      this.setRating(book.rating);
      this.setBookId(book.book_id);
    },

    // ------------------------------
    // Helper functions that set specific book properties
    // ------------------------------
    setTitle(title) {
      this.currentBookData.book.title = title;
    },
    setSlug(slug) {
      this.currentBookData.book.slug = slug;
    },
    setIsCompleted(isCompleted) {
      this.currentBookData.book.is_completed = isCompleted;
    },
    setRating(rating) {
      this.currentBookData.book.rating = rating;
    },
    setBookId(id) {
      this.currentBookData.book.book_id = id;
    },
    // ------------------------------
    // Helper functions that set internal store process properties
    // ------------------------------
    setCurrentStepHeading(heading) {
      this.currentStep.heading = heading;
    },
    setCurrentStepComponent(component) {
      this.currentStep.component = component;
    },
    setCurrentStepStatus(status) {
      this.currentStep.status = status;
    },
    // ------------------------------
    // Helper functions that logic chain based on pre-existing data
    // ------------------------------
    async createOrGetBookByTitle(title) {
      const res = await axios.post("/api/create-book/title", {
        title,
      });
      return res.data;
    },
    // ------------------------------
    // Helper functions that reset the store
    // ------------------------------
    resetStore() {
      this.currentBookData = initializeBookData();
      this.currentStep = initializeFirstStep();
    },
  },
});

export default useNewBookStore;
