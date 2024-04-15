<template>
  <form
    @submit.prevent="console.log('hello world')"
    class="p-4 mb-4 bg-white border rounded-md border-zinc-400 shadow-md"
  >
    <div class="mb-4">
      <label for="title" class="block mb-2 font-bold text-zinc-600 mr-6"
        >Add Version</label
      >
      <input
        type="text"
        id="title"
        name="title"
        placeholder="Title"
        v-model="version.title"
      />
      <p v-if="!isValid" class="p-2 text-red-300">
        Enter a name for this book.
      </p>
    </div>
    <!-- End version -->
    <div class="flex justify-end">
      <button class="btn btn-primary" type="submit">Add version</button>
    </div>
  </form>
</template>

<script>
export default {
  name: "NewVersionsInput",
  data() {
    return {
      version: {
        title: "",
      },
      isValid: true,
    };
  },
};
</script>
