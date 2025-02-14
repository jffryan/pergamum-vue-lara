import { createRouter, createWebHistory } from "vue-router";

import bookRoutes from "./book-routes";
import authorRoutes from "./author-routes";

const router = createRouter({
    history: createWebHistory(import.meta.env.BASE_URL),
    routes: [
        {
            path: "/",
            name: "home",
            component: () => import("@/views/HomeView.vue"),
        },
        ...bookRoutes,
        ...authorRoutes,
        // Before I reorganize this, I actually ought to just fix bookshelves to use query parameters and a single template
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
        {
            path: "/backlog",
            name: "backlog.home",
            component: () => import("@/views/BacklogHome.vue"),
            props: { innerComponent: "BacklogDashboard" },
        },
        {
            path: "/backlog/index",
            name: "backlog.index",
            component: () => import("@/views/BacklogHome.vue"),
            props: { innerComponent: "BacklogIndex" },
        },
        {
            path: "/completed",
            name: "completed.home",
            component: () => import("@/views/CompletedView.vue"),
        },
        {
            path: "/completed/statistics",
            name: "completed.statistics",
            component: () => import("@/views/CompletedStatisticsDashboard.vue"),
        },
        {
            path: "/404",
            name: "NotFound",
            component: () => import("@/views/ErrorNotFoundView.vue"),
        },
    ],
});

export default router;
