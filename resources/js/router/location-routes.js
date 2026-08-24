// Slug-routed from day one ('/locations/o1s5') — genres route by id and
// their plan carries an item to undo it.
const locationRoutes = [
    {
        path: "/locations",
        name: "locations.index",
        component: () => import("@/views/LocationsView.vue"),
    },
    {
        path: "/locations/:slug",
        name: "locations.show",
        component: () => import("@/views/LocationView.vue"),
    },
    {
        path: "/locations/:slug/statistics",
        name: "locations.statistics",
        component: () => import("@/views/LocationStatisticsView.vue"),
    },
];

export default locationRoutes;
