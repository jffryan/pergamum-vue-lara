import { createRouter, createWebHistory } from "vue-router";
import { useAuthStore } from "@/stores";

import adminRoutes from "./admin-routes";
import authorRoutes from "./author-routes";
import bookRoutes from "./book-routes";
import listRoutes from "./list-routes";
import locationRoutes from "./location-routes";

const router = createRouter({
    history: createWebHistory("/"),
    scrollBehavior() {
        return { top: 0 };
    },
    routes: [
        {
            path: "/",
            name: "home",
            component: () => import("@/views/HomeView.vue"),
            meta: { public: true },
        },
        {
            path: "/about",
            name: "about",
            component: () => import("@/views/AboutView.vue"),
            meta: { public: true },
        },
        {
            path: "/login",
            name: "login",
            component: () => import("@/views/LoginView.vue"),
            meta: { public: true },
        },
        {
            path: "/404",
            name: "NotFound",
            component: () => import("@/views/ErrorNotFoundView.vue"),
            meta: { public: true },
        },
        // Non-public routes below here
        {
            path: "/dashboard",
            name: "dashboard",
            component: () => import("@/views/UserDashboard.vue"),
        },
        ...bookRoutes,
        ...authorRoutes,
        ...listRoutes,
        ...locationRoutes,
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
            path: "/completed",
            name: "completed.home",
            component: () => import("@/views/CompletedView.vue"),
        },
        {
            path: "/statistics",
            name: "statistics",
            component: () => import("@/views/StatisticsDashboard.vue"),
        },
        ...adminRoutes,
    ],
});

router.beforeEach(async (to) => {
    const authStore = useAuthStore();

    if (!authStore.authChecked) {
        await authStore.fetchUser();
    }

    if (!to.meta.public && !authStore.isLoggedIn) {
        return { name: "login" };
    }

    if (to.name === "login" && authStore.isLoggedIn) {
        return { name: "home" };
    }

    // Explicit rather than an implicit `undefined`. vue-router treats both as
    // "proceed", but returning the same type on every path is what lets the
    // redirect branches above stand out as the exceptions.
    return true;
});

export default router;
