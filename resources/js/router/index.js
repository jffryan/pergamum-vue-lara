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
  ],
});

export default router;
