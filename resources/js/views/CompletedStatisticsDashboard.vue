<template>
    <div>
        <h1>Statistics Dashboard</h1>
        <div v-if="statistics" class="bg-zinc-800 text-white">
            <div class="grid grid-cols-12 gap-4 p-4">
                <div class="p-4 bg-slate-600 col-span-4">
                    <span class="block text-7xl font-bold mb-4">{{
                        totalBooksCompleted
                    }}</span>
                    <p class="ml-8 text-lg">Total Books Completed</p>
                </div>
                <div class="p-4 bg-slate-600 col-span-4">
                    <span class="block text-7xl font-bold mb-4">{{
                        totalBooks
                    }}</span>
                    <p class="ml-8 text-lg">Total Books</p>
                </div>
                <div class="p-4 bg-zinc-700 col-span-4">
                    <span class="block text-7xl font-bold mb-4"
                        >{{ percentageOfCollectionCompleted }}%</span
                    >
                    <p class="ml-8 text-lg">
                        Percentage of Collection Completed
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
                    <h3>
                        <router-link
                            :to="{ name: 'backlog.index' }"
                            class="hover:underline"
                            >Current Backlog</router-link
                        >
                    </h3>
                    <ul class="list-disc ml-4">
                        <li v-for="item in topBacklogItems" :key="item.id">
                            <router-link
                                :to="{
                                    name: 'books.show',
                                    params: { slug: item.book.slug },
                                }"
                                class="hover:underline"
                                >{{ item.book.title }}</router-link
                            >
                        </li>
                    </ul>
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
        totalBooks() {
            return this.statistics.total_books;
        },
        totalBooksCompleted() {
            // use reduce to sum up the total books read from each year
            return this.statistics.booksReadByYear.reduce((acc, year) => {
                return acc + year.total;
            }, 0);
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
        topBacklogItems() {
            return this.statistics.topBacklogItems;
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
