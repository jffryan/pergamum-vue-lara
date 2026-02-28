const listRoutes = [
    {
        path: "/lists",
        name: "lists.index",
        component: () => import("@/views/ListsView.vue"),
    },
    {
        path: "/lists/:id",
        name: "lists.show",
        component: () => import("@/views/ListView.vue"),
    },
    {
        path: "/lists/:id/statistics",
        name: "lists.statistics",
        component: () => import("@/views/ListStatisticsView.vue"),
    },
];

export default listRoutes;
