<template>
    <form
        @submit.prevent="submitGenres"
        class="p-4 mb-4 bg-white border rounded-md border-zinc-400 shadow-md"
    >
        <div class="mb-4">
            <label class="block font-bold text-zinc-600 mb-2">Add genres</label>
            <GenreTagInput v-model="genres" class="mb-4" />
            <p v-if="!isValid" class="p-2 text-red-300">
                At least one genre is required.
            </p>
        </div>
        <div class="flex justify-end">
            <button class="btn btn-primary" type="submit">Submit</button>
        </div>
    </form>
</template>

<script>
import { useNewBookStore } from "@/stores";
import GenreTagInput from "@/components/newBook/GenreTagInput.vue";

export default {
    name: "NewGenresInput",
    components: { GenreTagInput },
    setup() {
        const NewBookStore = useNewBookStore();
        return { NewBookStore };
    },
    data() {
        return {
            genres: [],
            isValid: true,
        };
    },
    methods: {
        async submitGenres() {
            this.isValid = this.genres.length > 0;
            if (!this.isValid) {
                return;
            }
            await this.NewBookStore.addGenresToNewBook(
                this.genres.map((g) => g.name),
            );
        },
    },
};
</script>
