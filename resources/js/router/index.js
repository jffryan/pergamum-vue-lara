import { createRouter, createWebHistory } from "vue-router";

const router = createRouter({
  history: createWebHistory(import.meta.env.BASE_URL),
  routes: [
    {
      path: "/",
      name: "home",
      component: () => import("@/views/HomeView.vue"),
    },
    {
      path: "/library",
      name: "library.index",
      component: () => import("@/views/LibraryView.vue"),
    },
    {
      path: "/add-books",
      name: "books.create",
      component: () => import("@/views/AddBooksView.vue"),
    },
    {
      path: "/add-books/bulk-upload",
      name: "books.bulk-add",
      component: () => import("@/views/BulkAddBooksView.vue"),
    },
    {
      path: "/books/:slug/edit",
      name: "books.edit",
      component: () => import("@/views/EditBookView.vue"),
    },
    {
      path: "/books/:slug",
      name: "books.show",
      component: () => import("@/views/BookView.vue"),
    },
    {
      path: "/authors/:slug",
      name: "authors.show",
      component: () => import("@/views/AuthorView.vue"),
    },
    {
      path: "/formats/:format",
      name: "formats.show",
      component: () => import("@/views/FormatView.vue"),
    },
    {
      path: "/genres",
      name: "genres.index",
      component: () => import("@/views/GenresView.vue"),
    },
    {
      path: "/genres/:id",
      name: "genres.show",
      component: () => import("@/views/GenreView.vue"),
    },
    {
      path: "/about",
      name: "about",
      component: () => import("@/views/AboutView.vue"),
    },
  ],
});

export default router;
