<template>
    <div>
        <div v-if="isLoading">
            <PageLoadingIndicator />
        </div>
        <div v-else-if="showErrorMessage">
            <AlertBox :message="error" alert-type="danger" />
        </div>
        <div v-else>
            <!-- Breadcrumb up the tree -->
            <div class="mb-2 text-sm text-gray-500">
                <router-link
                    :to="{ name: 'locations.index' }"
                    class="hover:underline"
                    >Locations</router-link
                >
                <template
                    v-for="ancestor in ancestors"
                    :key="ancestor.location_id"
                >
                    <span> / </span>
                    <router-link
                        :to="{
                            name: 'locations.show',
                            params: { slug: ancestor.slug },
                        }"
                        class="hover:underline"
                    >
                        {{ ancestor.name || ancestor.code }}
                    </router-link>
                </template>
            </div>

            <h1>{{ location.name || location.code }}</h1>
            <p class="mb-2 text-sm text-gray-600">
                {{ location.kind }} · {{ subtreeVersionsCount }} copies
                <router-link
                    :to="{
                        name: 'locations.statistics',
                        params: { slug: location.slug },
                    }"
                    class="ml-2 hover:underline text-gray-500"
                    >Statistics →</router-link
                >
            </p>

            <!-- Child locations -->
            <div v-if="children.length" class="mb-4 flex flex-wrap gap-2">
                <router-link
                    v-for="child in children"
                    :key="child.location_id"
                    :to="{
                        name: 'locations.show',
                        params: { slug: child.slug },
                    }"
                    class="inline-block px-2 py-1 rounded bg-slate-900 text-slate-200 text-sm hover:bg-slate-600"
                >
                    {{ child.name || child.code }}
                    <span class="opacity-75"
                        >({{ child.subtree_versions_count }})</span
                    >
                </router-link>
            </div>

            <div class="mb-4">
                <div
                    v-for="page in pagination"
                    :key="page.label"
                    class="inline mr-2"
                >
                    <router-link
                        v-if="page.url"
                        :to="page.url"
                        :class="page.active ? 'font-bold underline' : ''"
                    >
                        {{ page.label }}
                    </router-link>
                </div>
            </div>
            <BookshelfTable :books="books" />

            <!-- Only leaves are shelvable (see ShelfPicker) — a bookcase's
                 copies live on its shelves, never on the bookcase itself. -->
            <AddBookSearch
                v-if="children.length === 0"
                class="mt-6"
                :is-version-added="isVersionAdded"
                @add="addVersion"
            />
        </div>
    </div>
</template>

<script>
import { getLocationBooks, setVersionLocation } from "@/api/LocationController";
import { useLocationsStore, useStatisticsStore } from "@/stores";

import AddBookSearch from "@/components/books/AddBookSearch.vue";
import AlertBox from "@/components/globals/alerts/AlertBox.vue";
import BookshelfTable from "@/components/books/table/BookshelfTable.vue";
import PageLoadingIndicator from "@/components/globals/loading/PageLoadingIndicator.vue";

/**
 * One location: breadcrumb up, children down, and a paginated book listing
 * of the whole subtree (a bookcase page is the union of its shelves). A
 * shelf's listing arrives in physical left-to-right order — the server
 * defaults shelf-kind locations to the `shelf` sort. Leaf locations also get
 * an AddBookSearch so copies can be shelved from the shelf page itself.
 */
export default {
    name: "LocationView",
    components: {
        AddBookSearch,
        AlertBox,
        BookshelfTable,
        PageLoadingIndicator,
    },
    setup() {
        return {
            LocationsStore: useLocationsStore(),
            StatisticsStore: useStatisticsStore(),
        };
    },
    data() {
        return {
            isLoading: true,
            books: [],
            pagination: [],
            showErrorMessage: false,
            error: "",
            // Versions shelved here during this visit; the search results'
            // own location_id covers copies that were here already.
            shelvedVersionIds: new Set(),
        };
    },
    computed: {
        slug() {
            return this.$route.params.slug;
        },
        currentPage() {
            return this.$route.query.page || 1;
        },
        location() {
            return this.LocationsStore.currentLocation?.location ?? null;
        },
        ancestors() {
            return this.LocationsStore.currentLocation?.ancestors ?? [];
        },
        children() {
            return this.LocationsStore.currentLocation?.children ?? [];
        },
        subtreeVersionsCount() {
            return (
                this.LocationsStore.currentLocation?.subtree_versions_count ?? 0
            );
        },
    },
    methods: {
        async fetchAndSetLocationData() {
            this.isLoading = true;
            this.shelvedVersionIds = new Set();
            try {
                await this.loadLocationData();
            } catch (error) {
                console.error("Error fetching location:", error);
                this.showErrorMessage = true;
                this.error =
                    "Unable to load this location at this time. Please try again later.";
            } finally {
                this.isLoading = false;
            }
        },
        // Also used as a quiet refresh after shelving, so the search results
        // (and their "✓ Added" state) survive instead of being unmounted by
        // the isLoading toggle.
        async loadLocationData() {
            const [, booksRes] = await Promise.all([
                this.LocationsStore.fetchLocation(this.slug),
                getLocationBooks(this.slug, { page: this.currentPage }),
            ]);
            this.books = booksRes.data.books;
            this.pagination = this.buildPaginationLinks(
                booksRes.data.pagination,
            );
        },
        isVersionAdded(version) {
            return (
                version.location_id === this.location.location_id ||
                this.shelvedVersionIds.has(version.version_id)
            );
        },
        async addVersion(version) {
            try {
                await setVersionLocation(
                    version.version_id,
                    this.location.location_id,
                );
                this.shelvedVersionIds.add(version.version_id);
                this.StatisticsStore.invalidate("location", this.slug);
                await this.loadLocationData();
            } catch (error) {
                console.error("Error shelving version:", error);
            }
        },
        buildPaginationLinks(paginationData) {
            const { currentPage, lastPage } = paginationData;

            return [...Array(lastPage).keys()]
                .map((i) => i + 1)
                .map((label) => ({
                    label,
                    url: `?page=${label}`,
                    active: label === currentPage,
                }));
        },
    },
    watch: {
        "$route.params.slug": {
            immediate: true,
            handler: "fetchAndSetLocationData",
        },
        currentPage: "fetchAndSetLocationData",
    },
};
</script>
