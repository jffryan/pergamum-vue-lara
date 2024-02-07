<template>
  <form @submit.prevent="console.log('hello world')">
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
          />
        </div>

        <p v-if="!isValid.authors[idx]" class="p-2 text-red-300">
          Last name is required.
        </p>
      </div>
    </div>
    <!-- END AUTHOR INPUT -->
    <div class="flex justify-end">
      <button class="btn btn-secondary mr-4" @click.prevent="addAuthorInput">
        Add author
      </button>
      <button class="btn btn-primary" type="submit">Submit</button>
    </div>
  </form>
</template>

<script>
export default {
  name: "NewAuthorsInput",
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
  methods: {
    addAuthorInput(e) {
      e.preventDefault();

      // Limit to one blank input at a time
      const lastIndex = this.authors.length - 1;
      const lastAuthor = this.authors[lastIndex];
      if (lastAuthor.first_name !== "" || lastAuthor.last_name !== "") {
        const newAuthor = {
          first_name: "",
          last_name: "",
        };
        this.authors.push(newAuthor);
        // Add another isValid input
        this.isValid.authors.push({
          last_name: true,
        });
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
