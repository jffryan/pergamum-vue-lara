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
        meta: {component: "FormatsIndex"},
    },
]

export default adminRoutes;