const authorRoutes = [
    {
        path: "/authors/:slug",
        name: "authors.show",
        component: () => import("@/views/AuthorView.vue"),
    },
];

export default authorRoutes;
