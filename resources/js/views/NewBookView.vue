<template>
    <div>
        <div
            class="w-2/3 px-6 py-8 bg-zinc-300 border rounded-md border-zinc-400 mb-4 shadow-lg"
        >
            <h1>{{ currentStep }}</h1>
            <component
                v-for="component in currentComponents"
                :is="component"
                :key="component"
            />
        </div>
    </div>
</template>

<script>
import { useNewBookStore } from "@/stores";
import NewAuthorsInput from "@/components/newBook/NewAuthorsInput.vue";
import NewBookTitleInput from "@/components/newBook/NewBookTitleInput.vue";
import NewBookProgressForm from "@/components/newBook/NewBookProgressForm.vue";
import NewGenresInput from "@/components/newBook/NewGenresInput.vue";
import NewReadInstanceInput from "@/components/newBook/NewReadInstanceInput.vue";
import NewBookSubmitControls from "@/components/newBook/NewBookSubmitControls.vue";
import NewVersionsInput from "@/components/newBook/NewVersionsInput.vue";
import NewBookVersionConfirmation from "@/components/newBook/NewBookVersionConfirmation.vue";

export default {
    name: "NewBookView",
    components: {
        NewAuthorsInput,
        NewBookTitleInput,
        NewBookProgressForm,
        NewGenresInput,
        NewReadInstanceInput,
        NewBookSubmitControls,
        NewVersionsInput,
        NewBookVersionConfirmation,
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
