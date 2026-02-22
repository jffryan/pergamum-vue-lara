<template>
    <div>
        <div v-if="isLoading">
            <PageLoadingIndicator />
        </div>
        <div v-else-if="showErrorMessage">
            <AlertBox :message="error" alert-type="danger" />
        </div>
        <div v-else>
            <h1>{{ authorFullName }}</h1>
            <BookshelfTable :books="currentAuthor.books" />
        </div>
    </div>
</template>

<script>
import { getAuthorBySlug } from "@/api/AuthorController";
import { useAuthorsStore } from "@/stores";

import AlertBox from "@/components/globals/alerts/AlertBox.vue";
import BookshelfTable from "@/components/books/table/BookshelfTable.vue";
import PageLoadingIndicator from "@/components/globals/loading/PageLoadingIndicator.vue";

export default {
    name: "AuthorView",
    setup() {
        const AuthorsStore = useAuthorsStore();

        return {
            AuthorsStore,
        };
    },
    components: {
        AlertBox,
        BookshelfTable,
        PageLoadingIndicator,
    },
    data() {
        return {
            isLoading: true,
            showErrorMessage: false,
            error: "",
        };
    },
    computed: {
        // Why am I doing this in store instead of more directly? Who knows at this point. Consistency, I guess.
        currentAuthor() {
            return this.AuthorsStore.currentAuthor;
        },
        authorFullName() {
            if (this.currentAuthor && this.currentAuthor.author) {
                const firstName = this.currentAuthor.author.first_name || "";
                const lastName = this.currentAuthor.author.last_name || "";
                return `${firstName} ${lastName}`.trim();
            }
            return "Unknown Author";
        },
    },
    methods: {
        async fetchandSetData() {
            try {
                const res = await getAuthorBySlug(this.$route.params.slug);
                if (!res.data || res.status !== 200) {
                    throw new Error(
                        "Failed to fetch data: Invalid response from the server",
                    );
                }
                this.AuthorsStore.setCurrentAuthor(res.data);
            } catch (error) {
                // Log the error for debugging purposes
                console.error("Error fetching books:", error);

                // Provide user feedback
                this.showErrorMessage = true;
                this.error =
                    "Unable to load books at this time. Please try again later.";
            } finally {
                this.isLoading = false;
            }
        },
    },
    async mounted() {
        await this.fetchandSetData();
    },
};
</script>
