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
      path: "/books/:slug/edit",
      name: "books.edit",
      component: () => import("@/views/EditBookView.vue"),
    },
    {
      path: "/books/:slug",
      name: "books.show",
      component: () => import("@/views/BookView.vue"),
    },
  ],
});

export default router;
