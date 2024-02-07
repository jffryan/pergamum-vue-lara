<template>
  <nav class="h-screen">
    <ul>
      <li>
        <router-link :to="{ name: 'library.index' }">Library</router-link>
      </li>
      <li class="mb-2 ml-4">
        <router-link :to="{ name: 'genres.index' }">Genres</router-link>
      </li>
      <li>
        <router-link :to="{ name: 'books.create' }">Add book</router-link>
      </li>
      <li>
        <router-link :to="{ name: 'books.new' }">New book</router-link>
      </li>
      <li class="mb-2 ml-4">
        <router-link :to="{ name: 'books.bulk-add' }">Bulk add</router-link>
      </li>
      <li>
        <router-link :to="{ name: 'completed.home' }">Completed</router-link>
      </li>
      <li class="mb-2 ml-4">
        <router-link :to="{ name: 'completed.statistics' }"
          >Statistics</router-link
        >
      </li>
    </ul>
  </nav>
</template>

<script>
export default {
  name: "SidebarNav",
};
</script>
