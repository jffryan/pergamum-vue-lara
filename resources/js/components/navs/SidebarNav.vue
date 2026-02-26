<template>
    <nav class="h-screen">
        <ul>
            <!-- Library -->
            <div class="mb-2">
                <li>
                    <router-link :to="{ name: 'library.index' }">Library</router-link>
                </li>
                <li class="ml-4">
                    <router-link :to="{ name: 'genres.index' }">Genres</router-link>
                </li>
            </div>
            <!-- Actions -->
            <div class="mb-2">
                <li>
                    <router-link :to="{ name: 'books.new' }">New book</router-link>
                </li>
                <li>
                    <router-link :to="{ name: 'books.bulk-upload' }">Bulk upload</router-link>
                </li>
            </div>
            <div class="mb-2">
                <li>
                    <router-link :to="{ name: 'completed.home' }">Completed</router-link>
                </li>
                <li class="mb-2 ml-4">
                    <router-link :to="{ name: 'completed.statistics' }">Statistics</router-link>
                </li>
            </div>

        </ul>
    </nav>
</template>

<script>
export default {
    name: "SidebarNav",
};
</script>
