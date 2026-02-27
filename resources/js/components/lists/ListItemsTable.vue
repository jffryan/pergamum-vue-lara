<template>
    <div>
        <!-- Desktop table header -->
        <div class="hidden sm:grid grid-cols-12 bg-slate-900 text-slate-200 rounded-t-md">
            <div class="col-span-1 p-2">#</div>
            <div class="col-span-4 p-2">Title</div>
            <div class="col-span-3 p-2">Author</div>
            <div class="col-span-2 p-2">Format</div>
            <div class="col-span-1 p-2">Pages</div>
            <div class="col-span-1 p-2"></div>
        </div>

        <div
            v-for="(item, index) in items"
            :key="item.list_item_id"
            :class="[index % 2 === 0 ? 'bg-slate-100' : 'bg-slate-200']"
        >
            <!-- Mobile card layout -->
            <div class="sm:hidden flex items-start gap-2 p-3">
                <span class="text-gray-400 text-sm w-5 shrink-0 pt-0.5">{{ index + 1 }}</span>
                <div class="flex-1 min-w-0">
                    <router-link
                        :to="{ name: 'books.show', params: { slug: item.version.book.slug } }"
                        class="font-medium hover:underline block truncate"
                    >
                        {{ item.version.book.title }}
                    </router-link>
                    <div class="text-sm text-gray-600 mt-0.5 flex flex-wrap items-center gap-x-1.5">
                        <router-link
                            v-if="item.version.book.authors.length"
                            :to="{ name: 'authors.show', params: { slug: item.version.book.authors[0].slug } }"
                            class="hover:underline"
                        >
                            {{ authorName(item.version.book.authors[0]) }}
                        </router-link>
                        <span class="text-gray-400">·</span>
                        <router-link
                            :to="{ name: 'formats.show', params: { format: item.version.format.slug } }"
                            class="hover:underline"
                        >
                            {{ item.version.format.name }}
                        </router-link>
                        <template v-if="item.version.page_count">
                            <span class="text-gray-400">·</span>
                            <span>{{ item.version.page_count }}pp</span>
                        </template>
                    </div>
                </div>
                <button
                    v-if="showRemove"
                    @click="$emit('remove', item)"
                    class="text-red-500 hover:text-red-800 shrink-0"
                    title="Remove from list"
                >
                    ✕
                </button>
            </div>

            <!-- Desktop table row -->
            <div class="hidden sm:grid grid-cols-12 text-black hover:bg-slate-500 hover:text-white">
                <div class="col-span-1 p-2">{{ index + 1 }}</div>
                <div class="col-span-4 p-2">
                    <router-link
                        :to="{ name: 'books.show', params: { slug: item.version.book.slug } }"
                        class="block h-full w-full"
                    >
                        {{ item.version.book.title }}
                    </router-link>
                </div>
                <div class="col-span-3 p-2">
                    <router-link
                        v-if="item.version.book.authors.length"
                        :to="{ name: 'authors.show', params: { slug: item.version.book.authors[0].slug } }"
                        class="block h-full w-full"
                    >
                        {{ authorName(item.version.book.authors[0]) }}
                    </router-link>
                </div>
                <div class="col-span-2 p-2">
                    <router-link
                        :to="{ name: 'formats.show', params: { format: item.version.format.slug } }"
                        class="block h-full w-full"
                    >
                        {{ item.version.format.name }}
                    </router-link>
                </div>
                <div class="col-span-1 p-2">{{ item.version.page_count || "" }}</div>
                <div class="col-span-1 p-2 flex items-center justify-center">
                    <button
                        v-if="showRemove"
                        @click="$emit('remove', item)"
                        class="text-red-500 hover:text-red-800"
                        title="Remove from list"
                    >
                        ✕
                    </button>
                </div>
            </div>
        </div>

        <div v-if="items.length === 0" class="p-4 text-gray-500">
            No items in this list yet.
        </div>
    </div>
</template>

<script>
export default {
    name: "ListItemsTable",
    props: {
        items: {
            type: Array,
            required: true,
        },
        showRemove: {
            type: Boolean,
            default: false,
        },
    },
    emits: ["remove"],
    methods: {
        authorName(author) {
            const firstName = author.first_name || "";
            const lastName = author.last_name || "";
            return `${firstName} ${lastName}`.trim();
        },
    },
};
</script>
