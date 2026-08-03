<script>
import StatisticsGrid from "@/components/statistics/StatisticsGrid.vue";
import userDashboard from "@/services/statistics/surfaces/userDashboard";

export default {
    name: "UserDashboard",
    components: { StatisticsGrid },
    data() {
        return { surface: userDashboard };
    },
};
</script>

<template>
    <main>
        <h2 class="text-2xl font-bold mb-4">Welcome to your Dashboard</h2>
        <StatisticsGrid :surface="surface" />
    </main>
</template>
