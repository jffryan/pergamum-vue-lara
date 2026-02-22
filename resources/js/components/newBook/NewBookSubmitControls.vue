<template>
    <form
        @submit.prevent="submitBook"
        class="p-4 mb-4 bg-white border rounded-md border-zinc-400 shadow-md"
    >
        <label class="block font-bold text-zinc-600 mb-4"
            >Are you ready to submit this book?</label
        >
        <div class="flex justify-end">
            <button @click="resetForm" class="btn btn-secondary mr-4">
                Cancel
            </button>
            <button class="btn btn-primary" type="submit">Submit</button>
        </div>
    </form>
</template>

<script>
import { useNewBookStore } from "@/stores";

export default {
    name: "NewBookSubmitControls",
    setup() {
        const NewBookStore = useNewBookStore();

        return {
            NewBookStore,
        };
    },
    methods: {
        async submitBook() {
            const res = await this.NewBookStore.submitNewBook();
            if (res.data.success) {
                this.navigateToNewBook(res.data);
            }
        },
        resetForm() {
            this.NewBookStore.resetStore();
        },
        navigateToNewBook(data) {
            this.$router.push({
                name: "books.show",
                params: { slug: data.book.slug },
            });
        },
    },
};
</script>
