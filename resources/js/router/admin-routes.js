// `meta.adminMenu` is the single source of truth for the /admin landing page —
// AdminHome derives its link list from it, so adding an action here is enough
// to make it discoverable. `meta.component` is still the dispatch key
// AdminActionView looks up.
const adminRoutes = [
    {
        path: "/admin",
        name: "admin.home",
        component: () => import("@/views/admin/AdminHome.vue"),
    },
    {
        path: "/admin/formats",
        name: "admin.formats",
        component: () => import("@/views/admin/AdminActionView.vue"),
        meta: {
            component: "FormatsIndex",
            adminMenu: {
                title: "Manage Formats",
                description:
                    "See the formats a version can be filed under, and add new ones.",
            },
        },
    },
    {
        path: "/admin/genres",
        name: "admin.genres",
        component: () => import("@/views/admin/AdminActionView.vue"),
        meta: {
            component: "GenresIndex",
            adminMenu: {
                title: "Manage Genres",
                description: "Create, rename, merge, and delete genres.",
            },
        },
    },
    {
        path: "/admin/locations",
        name: "admin.locations",
        component: () => import("@/views/admin/AdminActionView.vue"),
        meta: {
            component: "LocationsIndex",
            adminMenu: {
                title: "Manage Locations",
                description:
                    "Create, rename, move, and delete the rooms, bookcases, and shelves copies live on.",
            },
        },
    },
];

export default adminRoutes;
