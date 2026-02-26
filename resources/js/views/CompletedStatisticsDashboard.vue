<template>
    <div>
        <h1>Statistics Dashboard</h1>
        <div v-if="statistics" class="bg-zinc-800 text-white">
            <div class="grid grid-cols-12 gap-4 p-4">
                <div class="p-4 bg-slate-600 col-span-3">
                    <span class="block text-7xl font-bold mb-4">{{
                        totalUniqueCompleted
                    }}</span>
                    <p class="ml-8 text-lg">Unique Books Read</p>
                </div>
                <div class="p-4 bg-slate-600 col-span-3">
                    <span class="block text-7xl font-bold mb-4">{{
                        totalReads
                    }}</span>
                    <p class="ml-8 text-lg">Total Reads (incl. re-reads)</p>
                </div>
                <div class="p-4 bg-slate-600 col-span-3">
                    <span class="block text-7xl font-bold mb-4">{{
                        totalBooks
                    }}</span>
                    <p class="ml-8 text-lg">Total Books in Catalog</p>
                </div>
                <div class="p-4 bg-zinc-700 col-span-3">
                    <span class="block text-7xl font-bold mb-4"
                        >{{ percentageOfCollectionCompleted }}%</span
                    >
                    <p class="ml-8 text-lg">
                        Percentage of Catalog Read
                    </p>
                </div>
                <div class="p-4 bg-zinc-700 col-span-4 row-span-2">
                    <h3>Total Books Read Per Year</h3>
                    <span
                        v-for="year in booksReadByYear"
                        :key="year.year"
                        class="block mb-2 text-lg"
                    >
                        <span class="font-bold">{{ year.year }}</span
                        >:
                        {{ year.total }}
                    </span>
                </div>
                <div class="p-4 bg-zinc-700 col-span-4 row-span-2">
                    <h3>Total Pages Read Per Year</h3>
                    <span
                        v-for="year in pagesReadByYear"
                        :key="year.year"
                        class="block mb-2 text-lg"
                    >
                        <span class="font-bold">{{ year.year }}</span
                        >:
                        {{ year.total }}
                    </span>
                </div>
                <div class="p-4 bg-zinc-700 col-span-4">
                    <h3>Newest Books</h3>
                    <ul class="list-disc ml-4">
                        <li v-for="book in newestBooks" :key="book.book_id">
                            <router-link
                                :to="{
                                    name: 'books.show',
                                    params: { slug: book.slug },
                                }"
                                class="hover:underline"
                                >{{ book.title }}</router-link
                            >
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
import axios from "axios";

export default {
    name: "CompletedStatisticsDashboard",
    data() {
        return {
            statistics: null,
        };
    },
    computed: {
        totalUniqueCompleted() {
            return this.statistics.total_books_read;
        },
        totalReads() {
            return this.statistics.booksReadByYear.reduce((acc, year) => {
                return acc + year.total;
            }, 0);
        },
        totalBooks() {
            return this.statistics.total_books;
        },
        percentageOfCollectionCompleted() {
            return this.statistics.percentageOfBooksRead;
        },
        booksReadByYear() {
            return this.statistics.booksReadByYear;
        },
        pagesReadByYear() {
            return this.statistics.totalPagesByYear;
        },
        newestBooks() {
            return this.statistics.newestBooks;
        },
    },
    async mounted() {
        try {
            const res = await axios.get("/api/statistics");
            this.statistics = res.data;
        } catch (err) {
            console.error(err);
        }
    },
};
</script>
