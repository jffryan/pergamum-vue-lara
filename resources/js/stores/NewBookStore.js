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
    authors: [],
    genres: [],
    versions: [],
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
          "NewAuthorsInput",
          "NewBookProgressForm",
        ]);
      }
      // If a prior model found, set the book data from the existing record
      if (res.exists) {
        this.setBookFromExisting(res.book);
        this.setCurrentStepComponent([
          "NewVersionsInput",
          "NewBookProgressForm",
        ]);
      }
    },
    addAuthorsToNewBook(authors) {
      // Check to ensure authors exists on the request
      if (!authors.length) {
        return;
      }

      /**
       We can actually save all this for later - for now we should just store it until we're ready to go to the DB
       
      // Format each author as an object with full name, first name, and last name
      const authorsData = authors.map((author) => {
        return {
          name: `${author.first_name} ${author.last_name}`,
          first_name: author.first_name,
          last_name: author.last_name,
        };
      });

      const res = await this.getOrSetToBeCreatedAuthorsByName(authorsData);
      */
      // Save the author data to the store
      this.setBookAuthors(authors);
      this.setCurrentStepComponent(["NewGenresInput", "NewBookProgressForm"]);
    },
    async addGenresToNewBook(genres) {
      // Check to ensure genres exists on the request
      if (!genres.length) {
        return;
      }
      const formattedGenres = genres.map((genre) => {
        return {
          name: genre,
          genre_id: null,
        };
      });
      // Save in the store for later
      this.setBookGenres(formattedGenres);
      this.setCurrentStepComponent(["NewVersionsInput", "NewBookProgressForm"]);
    },
    // ------------------------------
    // Helper functions that set specific model data
    // ------------------------------
    setBookFromExisting(book) {
      this.setSlug(book.slug);
      this.setIsCompleted(book.is_completed);
      this.setRating(book.rating);
      this.setBookId(book.book_id);
      this.setBookAuthors(book.authors);
      this.setBookGenres(book.genres);
      this.setBookVersions(book.versions);
      this.setCurrentStepHeading("Add new version");
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
    setBookAuthors(authors) {
      for (let i = 0; i < authors.length; i += 1) {
        const author = {
          name: `${authors[i].first_name} ${authors[i].last_name}`,
          author_id: authors[i].author_id,
        };

        this.currentBookData.authors[i] = author;
      }
    },
    setBookGenres(genres) {
      for (let i = 0; i < genres.length; i += 1) {
        const genre = {
          name: genres[i].name,
          genre_id: genres[i].genre_id,
        };

        this.currentBookData.genres[i] = genre;
      }
    },
    setBookVersions(versions) {
      this.currentBookData.versions = versions.map((version) => ({
        audio_runtime: version.audio_runtime,
        format: version.format.name,
        nickname: version.nickname,
        page_count: version.page_count,
        read_instances:
          version.read_instances && version.read_instances.length > 0
            ? version.read_instances.map((instance) => ({
                read_instances_id: instance.read_instances_id,
                date_read: instance.date_read,
              }))
            : [], // Handle cases where read_instances might be undefined or empty
        version_id: version.version_id,
      }));
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
    async getOrSetToBeCreatedAuthorsByName(authorsData) {
      const res = await axios.post("/api/create-authors", {
        authorsData,
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
