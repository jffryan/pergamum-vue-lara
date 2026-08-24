<template>
    <div>
        <div v-if="isLoading">
            <PageLoadingIndicator />
        </div>
        <div v-else-if="showErrorMessage">
            <AlertBox :message="error" alert-type="danger" />
        </div>
        <div v-else>
            <h1>Locations</h1>
            <p v-if="!roots.length" class="text-gray-500">No locations yet.</p>
            <div
                v-for="room in roots"
                :key="room.location_id"
                class="mb-6 p-4 rounded-md bg-slate-200"
            >
                <router-link
                    :to="{
                        name: 'locations.show',
                        params: { slug: room.slug },
                    }"
                    class="text-xl font-bold hover:underline"
                >
                    {{ room.name || room.code }}
                </router-link>
                <span class="ml-2 text-sm text-gray-600">
                    {{ subtreeCount(room) }} copies
                </span>

                <div
                    v-for="bookcase in childrenOf(room.location_id)"
                    :key="bookcase.location_id"
                    class="mt-3 ml-4"
                >
                    <router-link
                        :to="{
                            name: 'locations.show',
                            params: { slug: bookcase.slug },
                        }"
                        class="font-semibold hover:underline"
                    >
                        {{ bookcase.name || bookcase.code }}
                    </router-link>
                    <span class="ml-2 text-sm text-gray-600">
                        {{ subtreeCount(bookcase) }} copies
                    </span>

                    <div class="mt-1 ml-4 flex flex-wrap gap-2">
                        <router-link
                            v-for="shelf in childrenOf(bookcase.location_id)"
                            :key="shelf.location_id"
                            :to="{
                                name: 'locations.show',
                                params: { slug: shelf.slug },
                            }"
                            class="inline-block px-2 py-1 rounded bg-slate-900 text-slate-200 text-sm hover:bg-slate-600"
                        >
                            {{ shelf.name || shelf.code }}
                            <span class="opacity-75"
                                >({{ subtreeCount(shelf) }})</span
                            >
                        </router-link>
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<script>
import { useLocationsStore } from "@/stores";

import AlertBox from "@/components/globals/alerts/AlertBox.vue";
import PageLoadingIndicator from "@/components/globals/loading/PageLoadingIndicator.vue";

export default {
    name: "LocationsView",
    components: {
        AlertBox,
        PageLoadingIndicator,
    },
    setup() {
        return { LocationsStore: useLocationsStore() };
    },
    data() {
        return {
            isLoading: true,
            showErrorMessage: false,
            error: "",
        };
    },
    computed: {
        roots() {
            return this.LocationsStore.roots;
        },
    },
    methods: {
        childrenOf(location_id) {
            return this.LocationsStore.childrenOf(location_id);
        },
        /** Copies here plus everywhere below — the index payload only carries direct counts. */
        subtreeCount(location) {
            return this.childrenOf(location.location_id).reduce(
                (total, child) => total + this.subtreeCount(child),
                location.versions_count ?? 0,
            );
        },
    },
    async mounted() {
        try {
            await this.LocationsStore.fetchAllLocations();
        } catch (error) {
            console.error("Error fetching locations:", error);
            this.showErrorMessage = true;
            this.error =
                "Unable to load locations at this time. Please try again later.";
        } finally {
            this.isLoading = false;
        }
    },
};
</script>
