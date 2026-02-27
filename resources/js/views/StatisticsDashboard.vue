<template>
    <div>
        <h1>Statistics Dashboard</h1>
        <div v-if="statistics" class="bg-zinc-800 text-white">
            <div class="grid grid-cols-12 gap-2 sm:gap-4 p-4">
                <div class="p-3 sm:p-4 bg-slate-600 col-span-6 sm:col-span-3">
                    <span class="block text-5xl sm:text-7xl font-bold mb-2 sm:mb-4">{{
                        totalUniqueCompleted
                    }}</span>
                    <p class="text-sm sm:text-lg sm:ml-8">Unique Books Read</p>
                </div>
                <div class="p-3 sm:p-4 bg-slate-600 col-span-6 sm:col-span-3">
                    <span class="block text-5xl sm:text-7xl font-bold mb-2 sm:mb-4">{{
                        totalReads
                    }}</span>
                    <p class="text-sm sm:text-lg sm:ml-8">Total Reads (incl. re-reads)</p>
                </div>
                <div class="p-3 sm:p-4 bg-slate-600 col-span-6 sm:col-span-3">
                    <span class="block text-5xl sm:text-7xl font-bold mb-2 sm:mb-4">{{
                        totalBooks
                    }}</span>
                    <p class="text-sm sm:text-lg sm:ml-8">Total Books in Catalog</p>
                </div>
                <div class="p-3 sm:p-4 bg-zinc-700 col-span-6 sm:col-span-3">
                    <span class="block text-5xl sm:text-7xl font-bold mb-2 sm:mb-4">{{ percentageOfCollectionCompleted
                    }}%</span>
                    <p class="text-sm sm:text-lg sm:ml-8">
                        Percentage of Catalog Read
                    </p>
                </div>
                <div class="p-4 bg-zinc-700 col-span-12 sm:col-span-4 sm:row-span-2">
                    <h3>Total Books Read Per Year</h3>
                    <div class="grid grid-cols-2 sm:block">
                        <span v-for="year in booksReadByYear" :key="year.year" class="block mb-2 text-sm sm:text-lg">
                            <span class="font-bold">{{ year.year }}</span>:
                            {{ year.total }}
                        </span>
                    </div>

                </div>
                <div class="p-4 bg-zinc-700 col-span-12 sm:col-span-4 sm:row-span-2">
                    <h3>Total Pages Read Per Year</h3>
                    <div class="grid grid-cols-2 sm:block">
                        <span v-for="year in pagesReadByYear" :key="year.year" class="block mb-2 text-sm sm:text-lg">
                            <span class="font-bold">{{ year.year }}</span>:
                            {{ year.total }}
                        </span>
                    </div>

                </div>
                <div class="p-4 bg-zinc-700 col-span-12 sm:col-span-4">
                    <h3>Newest Books</h3>
                    <ul>
                        <li v-for="book in newestBooks" :key="book.book_id" class="mb-2 text-sm sm:text-lg">
                            <router-link :to="{
                                name: 'books.show',
                                params: { slug: book.slug },
                            }" class="hover:underline">{{ book.title }}</router-link>
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
