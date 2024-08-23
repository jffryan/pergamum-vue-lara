<template>
    <div>
        <BookCreateEditForm class="w-1/2" />
    </div>
</template>

<script>
import BookCreateEditForm from "@/components/books/forms/BookCreateEditForm.vue";

export default {
    name: "AddBooksView",
    components: {
        BookCreateEditForm,
    },
};
</script>
