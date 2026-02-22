<template>
    <form @submit.prevent="submitBookForm(this.bookForm)">
        <div class="w-full flex justify-between align-bottom mt-4 mb-6">
            <h2 class="mb-0">
                {{ isCreateMode ? "Create Book" : "Edit Book" }}
            </h2>
            <div>
                <button type="submit" class="btn btn-primary mr-4">
                    Submit
                </button>
                <router-link
                    v-if="isEditMode"
                    :to="{
                        name: 'books.show',
                        params: { slug: currentBook.slug },
                    }"
                    class="btn btn-secondary"
                    >Cancel</router-link
                >
            </div>
        </div>
        <!-- End header -->
        <div class="mb-8">
            <h3>Book information</h3>
            <div class="mb-4">
                <label
                    for="title"
                    class="block mb-2 font-bold text-zinc-600 mr-6"
                    >Title</label
                >
                <input
                    type="text"
                    id="title"
                    name="title"
                    placeholder="Title"
                    v-model="bookForm.book.title"
                />
                <p v-if="!isValid.book.title" class="p-2 text-red-300">
                    Enter a name for this book.
                </p>
            </div>
            <!-- End title -->
            <div class="mb-4">
                <div class="flex justify-between mb-2">
                    <label
                        for="author_first_name"
                        class="block font-bold text-zinc-600 mr-6"
                        >Author</label
                    >
                    <div class="flex justify-end">
                        <button
                            class="btn-inline"
                            @click.prevent="addAuthorInput"
                        >
                            Add more
                        </button>
                    </div>
                </div>

                <div
                    v-for="(author, idx) in bookForm.authors"
                    :key="author.author_id ? author.author_id : idx"
                    ref="author_fields"
                >
                    <div class="w-full text-right">
                        <button
                            class="btn-inline"
                            v-if="bookForm.authors.length > 1"
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
            <div class="mb-4">
                <label for="genres" class="block font-bold text-zinc-600 mb-2"
                    >Add genres, separated by a comma</label
                >
                <input
                    name="genres"
                    type="text"
                    placeholder="Genres"
                    class="block bg-dark-mode-100 w-full border-b border-zinc-400 p-2 mb-4"
                    v-model="bookForm.book.genres.raw"
                />
                <p v-if="!isValid.book.genres" class="p-2 text-red-300">
                    At least one genre is required.
                </p>
            </div>
            <!-- END GENRES -->
        </div>

        <div class="w-full flex justify-between align-bottom mb-4">
            <h3>Version information</h3>
            <button
                @click.prevent="addNewVersion"
                class="border border-slate-800 rounded-lg text-sm px-5 py-2.5"
            >
                Add new version
            </button>
        </div>
        <div
            v-for="(version, idx) in bookForm.versions"
            :key="version.version_id ? version.version_id : idx"
        >
            <div class="mb-4">
                <label
                    for="title"
                    class="block mb-2 font-bold text-zinc-600 mr-6"
                    >Format</label
                >
                <select
                    v-model="bookForm.versions[idx].format"
                    class="bg-zinc-100 text-zinc-700 border rounded p-2 focus:border-zinc-500 focus:outline-none"
                >
                    <option value="" class="text-zinc-400" disabled>
                        Select a format
                    </option>
                    <option
                        v-for="format in ConfigStore.books.formats"
                        :key="format.format_id"
                        :value="format.format_id"
                        class="text-zinc-700"
                    >
                        {{ format.name }}
                    </option>
                </select>
                <p
                    v-if="!isValid.versions[idx].format"
                    class="p-2 text-red-300"
                >
                    Format is required.
                </p>
            </div>
            <!-- END FORMAT SELECT -->
            <div class="mb-4">
                <label
                    for="nickname"
                    class="block mb-2 font-bold text-zinc-600 mr-6"
                    >Version Nickname</label
                >
                <input
                    type="text"
                    id="nickname"
                    name="nickname"
                    placeholder="Nickname"
                    v-model="bookForm.versions[idx].nickname"
                />
            </div>
            <!-- END VERSION NICKNAME -->
            <div class="flex justify-between gap-x-4 mb-4">
                <!-- Page count field -->
                <div class="mb-4 w-full">
                    <label
                        for="page_count"
                        class="block mb-2 font-bold text-zinc-600 mr-6"
                        >Page Count</label
                    >
                    <input
                        type="text"
                        id="page_count"
                        name="page_count"
                        placeholder="Page Count"
                        v-model="bookForm.versions[idx].page_count"
                        @input="
                            bookForm.versions[idx].page_count =
                                $event.target.value.replace(/[^0-9]/g, '')
                        "
                    />
                    <p
                        v-if="!isValid.versions[idx].page_count"
                        class="p-2 text-red-300"
                    >
                        Enter a valid page count.
                    </p>
                </div>

                <!-- Audio runtime field -->
                <div
                    v-if="bookForm.versions[idx].format === 2"
                    class="mb-4 w-full"
                >
                    <label
                        for="audio_runtime"
                        class="block mb-2 font-bold text-zinc-600 mr-6"
                        >Audio Runtime</label
                    >
                    <input
                        type="text"
                        id="audio_runtime"
                        name="audio_runtime"
                        placeholder="Audio Runtime"
                        v-model="bookForm.versions[idx].audio_runtime"
                        @input="
                            bookForm.versions[idx].audio_runtime =
                                $event.target.value.replace(/[^0-9]/g, '')
                        "
                    />
                    <p
                        v-if="!isValid.versions[idx].audio_runtime"
                        class="p-2 text-red-300"
                    >
                        Enter a valid audio runtime.
                    </p>
                </div>
            </div>
            <!-- END CONTENT LENGTH -->
        </div>
        <div class="mb-8">
            <h3>Read History</h3>
            <div class="mb-4 flex justify-between">
                <div class="mt-auto">
                    <label
                        class="relative inline-flex items-center cursor-pointer"
                    >
                        <input
                            type="checkbox"
                            v-model="bookForm.book.is_completed"
                            class="sr-only peer"
                            :true-value="1"
                            :false-value="0"
                        />
                        <div
                            class="w-11 h-6 bg-slate-400 rounded-full peer peer-checked:after:translate-x-full peer-checked:after:border-white after:content-[''] after:absolute after:top-[2px] after:left-[2px] after:bg-white after:border-slate-300 after:border after:rounded-full after:h-5 after:w-5 after:transition-all dark:border-gray-600 peer-checked:bg-gray-700"
                        ></div>
                        <span class="ml-3 font-medium"
                            >I finished this book</span
                        >
                    </label>
                </div>
            </div>
            <div v-if="bookForm.book.is_completed">
                <div
                    v-for="readInstance in bookForm.readInstances"
                    :key="readInstance.read_instances_id"
                    class="mb-4"
                >
                    <label
                        for="date_read"
                        class="block mb-2 font-bold text-zinc-600 mr-6"
                        >Date Completed</label
                    >
                    <input
                        type="text"
                        id="date_read"
                        name="date_read"
                        :value="readInstance.date_read"
                        @input="updateDateCompleted"
                        placeholder="MM/DD/YYYY"
                        class="border-2 border-t-transparent border-x-transparent border-b-zinc-400 p-2 w-full mb-2 focus:border-2 focus:outline-none focus:border-zinc-600 focus:rounded-md transition-all"
                    />
                </div>
                <div class="grid grid-cols-3">
                    <div
                        v-for="version in currentBook.versions"
                        :key="version.version_id"
                        @click="setReadInstanceVersion(version.version_id)"
                        class="bg-zinc-100 p-4 mb-4 border rounded-md border-zinc-400"
                    >
                        <p>{{ version.format.name }}</p>
                        <p v-if="version.format_id === 2">
                            {{ version.audio_runtime }}
                        </p>
                        <p>{{ version.page_count }}</p>
                    </div>
                </div>

                <!-- END DATE COMPLETED -->
                <div class="mb-4">
                    <label
                        for="rating"
                        class="block mb-2 font-bold text-zinc-600 mr-6"
                        >Rating</label
                    >
                    <select
                        v-model="bookForm.book.rating"
                        class="bg-zinc-100 text-zinc-700 border rounded p-2 focus:border-zinc-500 focus:outline-none"
                    >
                        <option value="" class="text-zinc-400" disabled>
                            Select a rating
                        </option>
                        <option
                            v-for="(rating, idx) in Array.from(
                                { length: 9 },
                                (_, i) => 1 + i * 0.5,
                            )"
                            :key="idx"
                            :value="rating"
                            class="text-zinc-700"
                        >
                            {{ rating }}
                        </option>
                    </select>
                </div>
                <!-- END RATING INPUT -->
            </div>
            <!-- END COMPLETED CONDITIONAL -->
        </div>
    </form>
</template>

<script>
import { useConfigStore, useBooksStore } from "@/stores";

import { splitAndNormalizeGenres } from "@/services/BookServices";

import {
    createBook,
    updateBook,
    getOneBookFromSlug,
} from "@/api/BookController";

import {
    validateString,
    validateAuthor,
    validateNumber,
} from "@/utils/validators";

export default {
    name: "BookCreateEditForm",
    props: {
        currentBook: {
            type: Object,
            default: null,
        },
    },
    setup() {
        const BooksStore = useBooksStore();
        const ConfigStore = useConfigStore();

        return {
            BooksStore,
            ConfigStore,
        };
    },
    data() {
        return {
            bookForm: this.initializeBookForm(),
            isValid: {
                book: {
                    title: true,
                    genres: true,
                },
                authors: [true],
                versions: [
                    {
                        format: true,
                        page_count: true,
                        audio_runtime: true,
                    },
                ],
            },
        };
    },
    computed: {
        isCreateMode() {
            // This only works for now. Eventually I want to use this form on other pages
            return this.$route.name === "books.create";
        },
        isEditMode() {
            return this.$route.path.includes("edit");
        },
    },
    methods: {
        // Default form data
        initializeBookForm() {
            return {
                book: {
                    title: "",
                    genres: {
                        raw: "",
                        parsed: [""],
                    },
                    is_completed: false,
                    is_backlog: false,
                    rating: "",
                },
                authors: [
                    {
                        first_name: "",
                        last_name: "",
                    },
                ],
                versions: [
                    {
                        format: "",
                        page_count: "",
                        audio_runtime: "",
                        nickname: "",
                    },
                ],
                readInstances: [
                    {
                        date_read: "",
                    },
                ],
            };
        },
        // Form UX functions
        addAuthorInput(e) {
            e.preventDefault();

            // Limit to one blank input at a time
            const lastIndex = this.bookForm.authors.length - 1;
            const lastAuthor = this.bookForm.authors[lastIndex];
            if (lastAuthor.first_name !== "" || lastAuthor.last_name !== "") {
                const newAuthor = {
                    first_name: "",
                    last_name: "",
                };
                this.bookForm.authors.push(newAuthor);
                // Add another isValid input
                this.isValid.authors.push({
                    last_name: true,
                });
            }
        },
        removeAuthorInput(index) {
            if (this.bookForm.authors.length > 1) {
                this.bookForm.authors.splice(index, 1);

                // Also remove the corresponding validation entry
                this.isValid.authors.splice(index, 1);
            }
        },
        updateDateCompleted(event) {
            const { value } = event.target;
            const adjustedValue = this.addSlashes(value);
            // A regex pattern that allows partially entered valid dates
            const partialRegex =
                /^((0[1-9]|1[0-2])\/?)?((0[1-9]|[12][0-9]|3[01])\/?)?((19|20)?\d{0,2})?$/;

            if (partialRegex.test(adjustedValue)) {
                this.bookForm.readInstances[0].date_read = adjustedValue;
            } else {
                // Try to rewrite this to fix the lint issue
                event.target.value = this.bookForm.readInstances[0].date_read;
            }
        },
        // Helper function for date input.
        addSlashes(value) {
            if (value.length === 2 || value.length === 5) {
                return `${value}/`;
            }
            return value;
        },
        addNewVersion() {
            // Limit to one blank input at a time
            const lastIndex = this.bookForm.versions.length - 1;
            const lastVersion = this.bookForm.versions[lastIndex];
            if (lastVersion.format !== "") {
                const newVersion = {
                    format: "",
                    page_count: "",
                    audio_runtime: "",
                    nickname: "",
                };
                this.bookForm.versions.push(newVersion);
                this.isValid.versions.push({
                    format: true,
                    page_count: true,
                    audio_runtime: true,
                });
            }
        },
        setReadInstanceVersion(versionId) {
            console.log(versionId);
        },
        formatBookToEdit(book) {
            const formattedBook = this.initializeBookForm();

            formattedBook.book.is_completed = book.is_completed;
            formattedBook.book.title = book.title;
            formattedBook.authors = book.authors;
            formattedBook.book_id = book.book_id;

            // BUGGY!
            for (let i = 0; i < formattedBook.authors.length; i += 1) {
                this.isValid.authors.push({
                    last_name: true,
                });
            }

            // Genres
            formattedBook.book.genres.raw = book.genres
                .map(
                    (genre) =>
                        `${genre.name.charAt(0).toUpperCase()}${genre.name
                            .slice(1)
                            .toLowerCase()}`,
                )
                .join(", ");

            const versions = [];
            for (let i = 0; i < book.versions.length; i += 1) {
                versions.push({
                    format: book.versions[i].format_id,
                    page_count: book.versions[i].page_count,
                    audio_runtime: book.versions[i].audio_runtime,
                    nickname: book.versions[i].nickname,
                    version_id: book.versions[i].version_id,
                });
                // BUGGY!
                this.isValid.versions.push({
                    format: true,
                    page_count: true,
                    audio_runtime: true,
                });
            }
            formattedBook.versions = versions;

            // Audio runtime
            if (book.is_completed) {
                formattedBook.readInstances = this.currentBook.read_instances;
                formattedBook.book.rating = book.rating;
            }

            return formattedBook;
        },
        // Form validation
        validateBook(bookForm) {
            const { title, genres } = bookForm.book;

            const versionsValidation = bookForm.versions.map((version) => ({
                format: validateNumber(version.format),
                page_count:
                    validateString(version.page_count) ||
                    validateNumber(version.page_count),
                audio_runtime: validateNumber(version.audio_runtime),
            }));

            const isValid = {
                book: {
                    title: validateString(title),
                    genres: validateString(genres.raw),
                },
                authors: bookForm.authors.map((author) =>
                    validateAuthor(author),
                ),
                versions: versionsValidation,
            };

            this.isValid = isValid;

            return Object.values(isValid.book).every((value) => value);
        },
        // Formatting submission
        formatBookForm(bookForm, parsedGenres) {
            const formattedBookForm = _.cloneDeep(bookForm);

            formattedBookForm.book.genres.parsed = parsedGenres;

            return formattedBookForm;
        },
        // Validate and format book
        validateAndFormatBook(bookForm) {
            const bookFormRequest = _.cloneDeep(bookForm);
            // Validate
            const isFormValid = this.validateBook(bookFormRequest);

            if (isFormValid) {
                // Set formatting
                const parsedGenres = splitAndNormalizeGenres(
                    bookForm.book.genres.raw,
                );
                const formattedBookForm = this.formatBookForm(
                    bookFormRequest,
                    parsedGenres,
                );

                return formattedBookForm;
            }

            return false;
        },
        async submitCreateForm(bookForm) {
            const bookFormRequest = _.cloneDeep(bookForm);
            // Validate
            const formattedBookForm =
                this.validateAndFormatBook(bookFormRequest);
            if (formattedBookForm) {
                const res = await createBook(formattedBookForm);

                this.BooksStore.addBook(res.data.book);

                this.$router.push({
                    name: "books.show",
                    params: { slug: res.data.book.slug },
                });
                return res;
            }
            return null;
        },
        async submitEditForm(bookForm) {
            const bookFormRequest = _.cloneDeep(bookForm);
            // Validate
            const formattedBookForm =
                this.validateAndFormatBook(bookFormRequest);
            if (formattedBookForm) {
                const res = await updateBook(formattedBookForm);

                this.BooksStore.updateBook(res.data.book);

                this.$router.push({
                    name: "books.show",
                    params: { slug: res.data.book.slug },
                });
                return res;
            }
            return null;
        },
        // Form submit process
        async submitBookForm(bookForm) {
            if (this.isCreateMode) {
                await this.submitCreateForm(bookForm);
            }

            if (this.isEditMode) {
                await this.submitEditForm(bookForm);
            }
        },
        async formatBookResponse(unFormattedBook) {
            const res = await getOneBookFromSlug(unFormattedBook.book.slug);
            return res.data;
        },
    },
    async created() {
        await this.ConfigStore.checkForFormats();
        if (this.currentBook) {
            this.bookForm = this.formatBookToEdit(this.currentBook);
        }
    },
};
</script>

<style scoped>
input[type="text"] {
    @apply border-2 border-t-transparent border-x-transparent border-b-zinc-400 p-2 w-full mb-2 focus:border-2 focus:outline-none focus:border-zinc-600 focus:rounded-md transition-all;
}
</style>
