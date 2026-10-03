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
                <template v-if="!isVirtual">{{ location.kind }} · </template
                >{{ subtreeVersionsCount }} copies
                <!-- The statistics scope resolves real locations only. -->
                <router-link
                    v-if="!isVirtual"
                    :to="{
                        name: 'locations.statistics',
                        params: { slug: location.slug },
                    }"
                    class="ml-2 hover:underline text-gray-500"
                    >Statistics →</router-link
                >
            </p>
            <p v-if="isVirtual" class="mb-4 text-sm text-gray-600">
                {{ location.description }}
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
            <AlertBox
                v-if="actionError"
                :message="actionError"
                alert-type="danger"
                class="mb-2"
            />

            <!-- The virtual pages are where copies get *out* of a holding
                 state, so each row carries the one transition that does it:
                 Shelve for the unshelved pen, Returned for copies on loan,
                 Restore for the discarded pile. Real shelves take copies in
                 via AddBookSearch below and move them from the book page. -->
            <BookshelfTable :books="books" per-copy>
                <template v-if="rowAction" #actions="{ book }">
                    <template v-if="rowAction === 'shelve'">
                        <button
                            v-if="pickingVersionId !== copyOf(book).version_id"
                            type="button"
                            class="underline hover:no-underline"
                            @click.stop="
                                pickingVersionId = copyOf(book).version_id
                            "
                        >
                            Shelve
                        </button>
                        <ShelfPicker
                            v-else
                            :version-id="copyOf(book).version_id"
                            @move="shelveCopy"
                            @cancel="pickingVersionId = null"
                        />
                    </template>
                    <button
                        v-else-if="rowAction === 'return'"
                        type="button"
                        class="underline hover:no-underline"
                        @click.stop="returnCopy(copyOf(book).version_id)"
                    >
                        Returned
                    </button>
                    <button
                        v-else-if="rowAction === 'restore'"
                        type="button"
                        class="underline hover:no-underline"
                        @click.stop="restoreCopy(copyOf(book).version_id)"
                    >
                        Restore
                    </button>
                </template>
            </BookshelfTable>

            <!-- Only leaves are shelvable (see ShelfPicker) — a bookcase's
                 copies live on its shelves, never on the bookcase itself,
                 and nothing is shelved *onto* a virtual location. -->
            <AddBookSearch
                v-if="!isVirtual && children.length === 0"
                class="mt-6"
                :is-version-added="isVersionAdded"
                @add="addVersion"
            />
        </div>
    </div>
</template>

<script>
import { getLocationBooks, setVersionLocation } from "@/api/LocationController";
import { restoreVersion, returnVersion } from "@/api/VersionController";
import { useLocationsStore, useStatisticsStore } from "@/stores";

import AddBookSearch from "@/components/books/AddBookSearch.vue";
import AlertBox from "@/components/globals/alerts/AlertBox.vue";
import BookshelfTable from "@/components/books/table/BookshelfTable.vue";
import PageLoadingIndicator from "@/components/globals/loading/PageLoadingIndicator.vue";
import ShelfPicker from "@/components/locations/ShelfPicker.vue";

// Which per-row transition a virtual location offers, by slug. The virtual
// locations themselves are a server-side registry (`VirtualLocations`); this
// is the SPA's half of it — a third virtual place is an entry here if it has
// a way out.
const ROW_ACTIONS = {
    unshelved: "shelve",
    "on-loan": "return",
    discarded: "restore",
};

/**
 * One location: breadcrumb up, children down, and a paginated listing of
 * the whole subtree (a bookcase page is the union of its shelves). Rows are
 * copies, not books — two copies of one novel here are two rows, so the
 * table agrees with the "N copies" count above it. A
 * shelf's listing arrives in physical left-to-right order — the server
 * defaults shelf-kind locations to the `shelf` sort. Leaf locations also get
 * an AddBookSearch so copies can be shelved from the shelf page itself.
 *
 * The same view serves the virtual locations (`unshelved`, `on-loan`,
 * `discarded`): the show payload has the same shape, flagged `virtual`, and
 * the differences are what the page *offers* — no statistics, no
 * AddBookSearch, and a per-row Shelve, Returned or Restore instead.
 */
export default {
    name: "LocationView",
    components: {
        AddBookSearch,
        AlertBox,
        BookshelfTable,
        PageLoadingIndicator,
        ShelfPicker,
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
            // The row whose ShelfPicker is open, on the unshelved page.
            pickingVersionId: null,
            actionError: "",
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
        isVirtual() {
            return Boolean(this.location?.virtual);
        },
        rowAction() {
            return this.isVirtual ? (ROW_ACTIONS[this.slug] ?? null) : null;
        },
    },
    methods: {
        async fetchAndSetLocationData() {
            this.isLoading = true;
            this.shelvedVersionIds = new Set();
            this.pickingVersionId = null;
            this.actionError = "";
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
        // Under per-copy every row carries exactly its own version.
        copyOf(book) {
            return book.versions[0];
        },
        // Every transition leaves this page's listing, so the table refetches
        // and the store's counts refresh (a virtual location's count is in
        // the same index payload as the shelves').
        async applyRowAction(request, failureMessage) {
            this.actionError = "";
            try {
                await request();
                await Promise.all([
                    this.loadLocationData(),
                    this.LocationsStore.fetchAllLocations({ force: true }),
                ]);
            } catch (error) {
                console.error(failureMessage, error);
                this.actionError = failureMessage;
            }
        },
        async shelveCopy({ version_id, location_id }) {
            this.pickingVersionId = null;
            // "— Unshelved —" on the unshelved page is where it already is.
            if (location_id === null) return;

            await this.applyRowAction(
                () => setVersionLocation(version_id, location_id),
                "Unable to shelve this copy. Please try again.",
            );
        },
        // A returned copy is back on its shelf (the loan never moved it), or
        // in the unshelved pen if it never had one.
        async returnCopy(version_id) {
            await this.applyRowAction(
                () => returnVersion(version_id),
                "Unable to mark this copy returned. Please try again.",
            );
        },
        async restoreCopy(version_id) {
            await this.applyRowAction(
                () => restoreVersion(version_id),
                "Unable to restore this copy. Please try again.",
            );
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
