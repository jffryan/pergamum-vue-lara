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
    read_instances: [],
    versions: [],
    addToBacklog: false,
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

      // Format each author as an object with full name, first name, and last name
      const authorsData = authors.map((author) => {
        return {
          name: `${author.first_name} ${author.last_name}`,
          first_name: author.first_name,
          last_name: author.last_name,
          author_id: null,
        };
      });

      // Save the author data to the store
      this.setBookAuthors(authorsData);
      this.setCurrentStepComponent(["NewGenresInput", "NewBookProgressForm"]);
    },
    addGenresToNewBook(genres) {
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
    addVersionToNewBook(version) {
      // Check to ensure versions exists on the request
      if (!version) {
        return;
      }

      // Format to work with setBookVersions
      const formattedVersion = {
        audio_runtime: version.audio_runtime,
        format: version.format,
        nickname: version.nickname,
        page_count: version.page_count,
        read_instances: [],
        version_id: null,
      };
      // Save in the store for later
      this.currentBookData.versions.push(formattedVersion);
      if (version.is_read) {
        this.setCurrentStepComponent([
          "NewReadInstanceInput",
          "NewBookProgressForm",
        ]);
        return;
      }

      this.setCurrentStepComponent([
        "NewBacklogItemInput",
        "NewBookProgressForm",
      ]);
    },
    addReadInstanceToNewBookVersion(readInstance) {
      // Check to ensure readInstance exists on the request
      if (!readInstance) {
        return;
      }

      // Format to work with setBookVersions
      const formattedReadInstance = {
        read_instances_id: null,
        date_read: readInstance.date_read,
      };

      // Save in the store for later
      this.currentBookData.versions[
        this.currentBookData.versions.length - 1
      ].read_instances.push(formattedReadInstance);

      this.currentBookData.read_instances.push(formattedReadInstance);

      this.currentBookData.book.is_completed = true;
      this.currentBookData.book.rating = readInstance.rating;

      this.setCurrentStepComponent([
        "NewBookProgressForm",
        "NewBookSubmitControls",
      ]);
      this.setCurrentStepHeading("Review book details");
    },
    addReadInstanceToExistingBookVersion(readInstance, selectedVersion) {
      // Check to ensure readInstance exists on the request
      if (!readInstance) {
        return;
      }

      const formattedReadInstance = readInstance;

      formattedReadInstance.version_id = selectedVersion.version_id;
      formattedReadInstance.book_id = selectedVersion.book_id;

      this.currentBookData.book.is_completed = true;
      this.currentBookData.book.rating = readInstance.rating;
      this.currentBookData.read_instances.push(formattedReadInstance);
    },
    setBacklogItemToNewBook(backlogItem) {
      // Check to ensure backlogItem exists on the request
      if (!backlogItem) {
        this.setCurrentStepComponent([
          "NewBookSubmitControls",
          "NewBookProgressForm",
        ]);
        this.setCurrentStepHeading("Review book details");
        return;
      }
      this.currentBookData.addToBacklog = true;
      this.setCurrentStepComponent([
        "NewBookSubmitControls",
        "NewBookProgressForm",
      ]);
      this.setCurrentStepHeading("Review book details");
    },
    async submitNewBook() {
      const res = await axios.post("/api/create-book", {
        bookData: this.currentBookData,
      });
      return res;
    },
    // ------------------------------
    // Helper functions that set specific model data
    // ------------------------------
    setBookFromExisting(book) {
      // Sometimes the form doesn't enter from setBookFromTitle, so we need to set title separately
      if (!this.currentBookData.book.title) {
        this.setTitle(book.title);
      }
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
          first_name: authors[i].first_name,
          last_name: authors[i].last_name,
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
        format: version.format,
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
