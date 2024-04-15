<template>
  <form
    @submit.prevent="submitAuthors"
    class="p-4 mb-4 bg-white border rounded-md border-zinc-400 shadow-md"
  >
    <div class="mb-4">
      <div class="flex justify-between mb-2">
        <label
          for="author_first_name"
          class="block font-bold text-zinc-600 mr-6"
          >Author</label
        >
      </div>

      <div
        v-for="(author, idx) in authors"
        :key="author.author_id ? author.author_id : idx"
        ref="author_fields"
      >
        <div class="w-full text-right">
          <button
            class="btn-inline"
            v-if="authors.length > 1"
            @click.prevent="removeAuthorInput(idx)"
          >
            Remove
          </button>
        </div>

        <div class="flex justify-between gap-x-4">
          <input
            id="author_first_name"
            name="author_first_name"
            type="text"
            placeholder="First"
            class="block"
            v-model="author.first_name"
          />
          <input
            id="author_last_name"
            name="author_last_name"
            type="text"
            placeholder="Last"
            class="block"
            v-model="author.last_name"
            @input="clearValidation(idx)"
          />
        </div>

        <p v-if="!isValid.authors[idx]" class="p-2 text-red-300">
          Last name is required.
        </p>
      </div>
    </div>
    <!-- END AUTHOR INPUT -->
    <div class="flex justify-end">
      <button
        v-if="canAddMoreAuthors || authors.length > 1"
        :disabled="!canAddMoreAuthors"
        class="btn btn-secondary mr-4"
        :class="
          canAddMoreAuthors
            ? ''
            : 'cursor-not-allowed text-zinc-400 hover:bg-white hover:text-zinc-400'
        "
        @click.prevent="addAuthorInput"
      >
        Add more authors
      </button>
      <button class="btn btn-primary" type="submit">Submit</button>
    </div>
  </form>
</template>

<script>
import { validateString } from "@/utils/validators";

import { useNewBookStore } from "@/stores";

export default {
  name: "NewAuthorsInput",
  setup() {
    const NewBookStore = useNewBookStore();

    return {
      NewBookStore,
    };
  },
  data() {
    return {
      authors: [
        {
          first_name: "",
          last_name: "",
        },
      ],
      isValid: {
        authors: [true],
      },
    };
  },
  computed: {
    canAddMoreAuthors() {
      // Limit to one blank input at a time
      const lastIndex = this.authors.length - 1;
      const lastAuthor = this.authors[lastIndex];
      if (lastAuthor.first_name !== "" || lastAuthor.last_name !== "") {
        return true;
      }
      return false;
    },
  },

  methods: {
    async submitAuthors() {
      const isValid = this.validateAuthors();
      if (!isValid) {
        return;
      }
      await this.NewBookStore.addAuthorsToNewBook(this.authors);
    },
    validateAuthors() {
      this.isValid.authors = this.authors.map((author) => {
        return validateString(author.last_name);
      });
      return this.isValid.authors.every((isValid) => isValid);
    },
    clearValidation(index) {
      this.isValid.authors[index] = true;
    },
    addAuthorInput(e) {
      e.preventDefault();
      if (this.canAddMoreAuthors) {
        const newAuthor = {
          first_name: "",
          last_name: "",
        };
        this.authors.push(newAuthor);
        // Add another isValid input
        this.isValid.authors.push(true);
      }
    },
    removeAuthorInput(index) {
      if (this.authors.length > 1) {
        this.authors.splice(index, 1);

        // Also remove the corresponding validation entry
        this.isValid.authors.splice(index, 1);
      }
    },
  },
};
</script>
