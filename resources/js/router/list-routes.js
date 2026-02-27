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
];

export default listRoutes;
