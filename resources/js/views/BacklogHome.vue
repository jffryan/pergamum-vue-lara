<template>
    <div v-if="isLoading">
        <PageLoadingIndicator />
    </div>
    <div v-else-if="showErrorMessage">
        <AlertBox :message="error" alert-type="danger" />
    </div>
    <div v-else>
        <component :is="innerComponent"></component>
    </div>
</template>

<script>
import { useBacklogStore } from "@/stores";
import { fetchBacklog } from "@/api/BacklogController";

import AlertBox from "@/components/globals/alerts/AlertBox.vue";
import BacklogDashboard from "@/components/backlog/BacklogDashboard.vue";
import BacklogIndex from "@/components/backlog/BacklogIndex.vue";
import PageLoadingIndicator from "@/components/globals/loading/PageLoadingIndicator.vue";

export default {
    name: "BacklogHome",
    components: {
        AlertBox,
        BacklogDashboard,
        BacklogIndex,
        PageLoadingIndicator,
    },
    props: {
        innerComponent: {
            type: String,
            required: true,
        },
    },
    setup() {
        const BacklogStore = useBacklogStore();

        return {
            BacklogStore,
        };
    },
    data() {
        return {
            isLoading: true,
            showErrorMessage: false,
            error: "",
        };
    },
    methods: {
        setErrorMessage(error) {
            this.error = error.message;
            this.showErrorMessage = true;
            this.isLoading = false;
        },
        async fetchBacklog() {
            try {
                const res = await fetchBacklog();
                return res;
            } catch (error) {
                this.setErrorMessage(error);
                return error;
            }
        },
    },
    async mounted() {
        if (!this.BacklogStore.backlog) {
            const res = await this.fetchBacklog();
            if (res instanceof Error) {
                this.setErrorMessage(res);
            } else {
                this.BacklogStore.setBacklog(res.data);
                this.isLoading = false;
            }
        }
        this.isLoading = false;
    },
};
</script>
