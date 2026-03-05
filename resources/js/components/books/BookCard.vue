<template>
    <router-link
        :to="{ name: 'books.show', params: { slug: book.book.slug } }"
        class="block p-4 rounded-md border border-slate-200 hover:border-slate-400 bg-white transition-colors"
    >
        <p class="font-semibold leading-snug mb-1">{{ book.book.title }}</p>
        <p class="text-sm text-zinc-500">
            <span
                v-for="(author, index) in formattedAuthors"
                :key="author.slug"
            >
                {{ author.name
                }}<span v-if="index < formattedAuthors.length - 1">, </span>
            </span>
        </p>
    </router-link>
</template>

<script>
export default {
    name: "BookCard",
    props: {
        book: {
            type: Object,
            required: true,
        },
    },
    computed: {
        formattedAuthors() {
            return this.book.authors.map((author) => ({
                name: `${author.first_name || ""} ${author.last_name || ""}`.trim(),
                slug: author.slug,
            }));
        },
    },
};
</script>
