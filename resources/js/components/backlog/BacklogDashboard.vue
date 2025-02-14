<template>
    <div>
        <div class="mb-12">
            <h1>Backlog</h1>
            <router-link :to="{ name: 'backlog.index' }" class="btn btn-primary"
                >View Remaining Backlog</router-link
            >
        </div>
        <!-- Expand this to be a widget for if I'm on track or not -->
        <h2>
            Days Remaining: <span class="font-bold">{{ daysRemaining }}</span>
        </h2>
        <BacklogCounter />
    </div>
</template>

<script>
import BacklogCounter from "@/components/backlog/BacklogCounter.vue";

export default {
    name: "BacklogHome",
    components: {
        BacklogCounter,
    },
    data() {
        return {
            daysRemaining: null,
        };
    },
    methods: {
        // Kind of cute to put this here but whatever
        daysUntilEndOfYear() {
            const currentDate = new Date();
            const endOfYear = new Date("2025-12-31");

            // Calculate the difference in milliseconds
            const diffInMilliseconds = endOfYear - currentDate;

            // Convert milliseconds to days (1 day = 24 hours = 1440 minutes = 86400000 milliseconds)
            const diffInDays = Math.ceil(
                diffInMilliseconds / (1000 * 60 * 60 * 24),
            );

            // Return the difference in days, subtract 1 for non-inclusive
            return diffInDays - 1;
        },
    },
    mounted() {
        this.daysRemaining = this.daysUntilEndOfYear();
    },
};
</script>
