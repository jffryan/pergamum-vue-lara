<template>
    <form
        @submit.prevent="submitBook()"
        class="p-4 mb-4 bg-white border rounded-md border-zinc-400 shadow-md"
    >
        <div class="mb-4">
            <label for="title" class="block mb-2 font-bold text-zinc-600 mr-6"
                >Enter Title</label
            >
            <input
                type="text"
                id="title"
                name="title"
                placeholder="Title"
                v-model="book.title"
            />
            <p v-if="!isValid" class="p-2 text-red-300">
                Enter a name for this book.
            </p>
        </div>
        <!-- End title -->
        <div class="flex justify-end">
            <button class="btn btn-primary" type="submit">Add book</button>
        </div>
    </form>
</template>

<script>
import { validateString } from "@/utils/validators";

import { useNewBookStore } from "@/stores";

export default {
    name: "NewBookTitleInput",
    setup() {
        const NewBookStore = useNewBookStore();

        return {
            NewBookStore,
        };
    },
    data() {
        return {
            book: {
                title: "",
            },
            isValid: true,
        };
    },
    methods: {
        async submitBook() {
            const isValid = this.validateBook();
            if (!isValid) {
                return;
            }
            await this.NewBookStore.beginBookCreation(this.book);
        },
        validateBook() {
            this.isValid = validateString(this.book.title);
            return this.isValid;
        },
    },
};
</script>
