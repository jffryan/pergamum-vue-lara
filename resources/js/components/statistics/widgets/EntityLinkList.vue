<script>
/**
 * A list of router-links — the newest-books card, and anything else that
 * hands back rows the reader should be able to click through to.
 */
export default {
    name: "EntityLinkList",
    props: {
        items: {
            type: Array,
            default: () => [],
        },
        routeName: {
            type: String,
            default: "books.show",
        },
        paramKey: {
            type: String,
            default: "slug",
        },
        paramName: {
            type: String,
            default: "slug",
        },
        labelKey: {
            type: String,
            default: "title",
        },
        keyField: {
            type: String,
            default: "book_id",
        },
        emptyMessage: {
            type: String,
            default: "Nothing here yet.",
        },
    },
    methods: {
        routeFor(item) {
            return {
                name: this.routeName,
                params: { [this.paramName]: item[this.paramKey] },
            };
        },
    },
};
</script>

<template>
    <div>
        <p v-if="!items.length" class="text-sm text-zinc-300">
            {{ emptyMessage }}
        </p>
        <ul v-else>
            <li
                v-for="item in items"
                :key="item[keyField]"
                class="mb-2 text-sm sm:text-lg"
            >
                <router-link :to="routeFor(item)" class="hover:underline">
                    {{ item[labelKey] }}
                </router-link>
            </li>
        </ul>
    </div>
</template>
