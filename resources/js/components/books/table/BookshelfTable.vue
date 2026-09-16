<template>
    <div>
        <h3 class="capitalize">{{ bookshelfTitle }}</h3>
        <div>
            <div
                class="hidden sm:grid grid-cols-12 bg-slate-900 text-slate-200 rounded-t-md"
            >
                <component
                    :is="isSortable(column) ? 'button' : 'div'"
                    v-for="column in columns"
                    :key="column.name"
                    :type="isSortable(column) ? 'button' : null"
                    @click="isSortable(column) && emitSort(column)"
                    :aria-sort="ariaSort(column)"
                    :class="[
                        'p-2 flex justify-between align-bottom text-left',
                        isSortable(column) ? 'cursor-pointer' : '',
                        spanClass(column.span),
                    ]"
                >
                    {{ column.name }}
                    <UpArrow
                        v-if="isSortable(column) && column.sortKey === sortKey"
                        :class="[
                            sortDirection === 'desc' ? 'arrow-down' : '',
                            'fill-white',
                        ]"
                    />
                </component>
            </div>
            <div>
                <BookTableRow
                    v-for="(book, index) in books"
                    :key="rowKey(book)"
                    :book="book"
                    :per-copy="perCopy"
                    :class="[
                        index % 2 === 0 ? 'bg-slate-100' : 'bg-slate-200',
                        ' text-black cursor-pointer hover:bg-slate-500 hover:text-white',
                    ]"
                />
            </div>
        </div>
    </div>
</template>

<script>
import BookTableRow from "@/components/books/table/BookTableRow.vue";
import UpArrow from "@/components/globals/svgs/UpArrow.vue";

// Tailwind's scanner reads source text, so an interpolated `col-span-${n}`
// class is invisible to it. These only survived before by coincidence — the
// same literals happened to appear in BookTableRow. Keep the mapping explicit
// so a new column with a new span doesn't silently render unstyled.
const SPAN_CLASSES = {
    1: "col-span-1",
    2: "col-span-2",
    3: "col-span-3",
};

export default {
    name: "BookshelfTable",
    components: {
        BookTableRow,
        UpArrow,
    },
    props: {
        books: {
            type: Array,
            required: true,
        },
        bookshelfTitle: {
            type: String,
            required: false,
            default: "All Books",
        },
        // Sorting is server-side, so a table can only offer it if its parent
        // fetches through an endpoint that supports `?sort=`. Views backed by
        // a plain relation load leave this off and get inert headers.
        sortable: {
            type: Boolean,
            required: false,
            default: false,
        },
        // The key currently sorted on, matching BookListing::SORTABLE.
        sortKey: {
            type: String,
            required: false,
            default: null,
        },
        sortDirection: {
            type: String,
            required: false,
            default: "asc",
        },
        // Location pages list copies rather than books, so one book can
        // appear on several rows (see LocationController::books). Rows then
        // key on the copy and show what tells copies apart.
        perCopy: {
            type: Boolean,
            required: false,
            default: false,
        },
    },
    emits: ["sort"],
    data() {
        return {
            columns: [
                { name: "Title", span: 3, sortKey: "title" },
                { name: "Primary Author", span: 2, sortKey: "author" },
                { name: "Format", span: 1, sortKey: "format" },
                { name: "Page Count", span: 1, sortKey: "pages" },
                // Books render up to two genres, so there is no single value
                // to order on.
                { name: "Genres", span: 3, sortKey: null },
                { name: "Date Read", span: 1, sortKey: "date_read" },
                { name: "Rating", span: 1, sortKey: "rating" },
            ],
        };
    },
    methods: {
        rowKey(book) {
            return this.perCopy
                ? `copy-${book.versions[0]?.version_id ?? book.book.book_id}`
                : book.book.book_id;
        },
        isSortable(column) {
            return this.sortable && Boolean(column.sortKey);
        },
        spanClass(span) {
            return SPAN_CLASSES[span] || "";
        },
        ariaSort(column) {
            if (!this.isSortable(column)) return null;
            if (column.sortKey !== this.sortKey) return "none";
            return this.sortDirection === "desc" ? "descending" : "ascending";
        },
        // Clicking the active column flips direction; clicking any other
        // starts it ascending. The parent owns the state but not this rule —
        // otherwise every consumer reimplements the toggle.
        emitSort(column) {
            const direction =
                column.sortKey === this.sortKey && this.sortDirection === "asc"
                    ? "desc"
                    : "asc";

            this.$emit("sort", { key: column.sortKey, direction });
        },
    },
};
</script>

<style>
.arrow-down {
    transform: rotate(180deg);
}
</style>
