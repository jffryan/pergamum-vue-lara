<script>
import StatisticsGrid from "@/components/statistics/StatisticsGrid.vue";
import userStatistics from "@/services/statistics/surfaces/userStatistics";

export default {
    name: "CompletedStatisticsDashboard",
    components: { StatisticsGrid },
    data() {
        return { surface: userStatistics };
    },
};
</script>

<template>
    <div>
        <h1>Statistics Dashboard</h1>
        <StatisticsGrid :surface="surface" />
    </div>
</template>
