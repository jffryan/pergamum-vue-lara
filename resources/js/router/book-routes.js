const bookRoutes = [
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
        path: "/new-book/",
        name: "books.new",
        component: () => import("@/views/NewBookView.vue"),
    },
    {
        path: "/add-books/bulk-upload",
        name: "books.bulk-add",
        component: () => import("@/views/BulkAddBooksView.vue"),
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
    {
        path: "/books/:slug/add-read-history",
        name: "books.add-read-history",
        component: () => import("@/views/AddReadHistoryView.vue"),
    },
    {
        path: "/books/:slug/new-version",
        name: "books.add-version",
        component: () => import("@/views/AddVersionView.vue"),
    },
];

export default bookRoutes;
