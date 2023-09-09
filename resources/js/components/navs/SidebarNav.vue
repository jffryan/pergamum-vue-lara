<template>
  <nav class="bg-slate-400 h-full py-10 px-8">
    <ul>
      <li class="mb-6">
        <router-link to="/" class="font-bold">Pergamum</router-link>
      </li>
      <li class="mb-2">
        <router-link to="/library">Library</router-link>
      </li>
      <li>
        <router-link to="/add-books">Add books</router-link>
      </li>
    </ul>
  </nav>
</template>

<script>
export default {
  name: "SidebarNav",
};
</script>

