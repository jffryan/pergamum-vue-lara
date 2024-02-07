<template>
  <div>
    <h1>{{ currentStep }}</h1>
    <component
      v-for="component in currentComponents"
      :is="component"
      :key="component"
      class="w-2/3 px-6 py-8 border rounded-md border-zinc-400 mb-4"
    />
  </div>
</template>

<script>
import { useNewBookStore } from "@/stores";
import NewAuthorsInput from "@/components/newBook/NewAuthorsInput.vue";
import NewBookTitleInput from "@/components/newBook/NewBookTitleInput.vue";
import NewBookProgressForm from "@/components/newBook/NewBookProgressForm.vue";

export default {
  name: "NewBookView",
  components: {
    NewAuthorsInput,
    NewBookTitleInput,
    NewBookProgressForm,
  },
  setup() {
    const NewBookStore = useNewBookStore();

    return {
      NewBookStore,
    };
  },
  computed: {
    currentStep() {
      return this.NewBookStore.currentStep.heading || "Book";
    },
    currentComponents() {
      return this.NewBookStore.currentStep.component || "div";
    },
  },
  created() {
    this.NewBookStore.resetStore();
  },
};
</script>
