<template>
    <div>
        <div v-if="isLoading">
            <PageLoadingIndicator />
        </div>
        <div v-else-if="showErrorMessage">
            <AlertBox :message="error" alert-type="danger" />
        </div>
        <div v-else>
            <router-link
                :to="{ name: 'locations.show', params: { slug } }"
                class="block mb-4 text-sm text-gray-500 hover:underline"
            >
                ← Back to {{ locationLabel }}
            </router-link>

            <h1 class="text-2xl font-bold mb-4">
                {{ locationLabel }}: Statistics
            </h1>

            <StatisticsGrid :surface="surface" :scope-id="slug" />
        </div>
    </div>
</template>

<script>
import { useLocationsStore } from "@/stores";
import locationStatistics from "@/services/statistics/surfaces/locationStatistics";

import AlertBox from "@/components/globals/alerts/AlertBox.vue";
import PageLoadingIndicator from "@/components/globals/loading/PageLoadingIndicator.vue";
import StatisticsGrid from "@/components/statistics/StatisticsGrid.vue";

/**
 * The numbers come from the statistics endpoint via `StatisticsGrid` — the
 * backend location scope resolves the slug and reports over the whole
 * subtree, so shelf, bookcase and room all land here. This view only
 * supplies the location's name.
 */
export default {
    name: "LocationStatisticsView",
    components: {
        AlertBox,
        PageLoadingIndicator,
        StatisticsGrid,
    },
    setup() {
        return {
            LocationsStore: useLocationsStore(),
            surface: locationStatistics,
        };
    },
    data() {
        return {
            isLoading: true,
            showErrorMessage: false,
            error: "",
        };
    },
    computed: {
        slug() {
            return this.$route.params.slug;
        },
        locationLabel() {
            const location = this.LocationsStore.bySlug(this.slug);

            return location ? location.name || location.code : this.slug;
        },
    },
    async mounted() {
        try {
            await this.LocationsStore.fetchAllLocations();
        } catch (error) {
            console.error("Error fetching locations:", error);
            this.showErrorMessage = true;
            this.error =
                "Unable to load statistics for this location. Please try again later.";
        } finally {
            this.isLoading = false;
        }
    },
};
</script>
