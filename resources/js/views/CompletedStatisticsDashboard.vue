<template>
  <div>
    <h1>Completed Statistics Dashboard</h1>
  </div>
</template>

<script>
import axios from "axios";
export default {
  name: "CompletedStatisticsDashboard",
  mounted() {
    axios
      .get("/api/statistics")
      .then((response) => {
        console.log(response.data);
      })
      .catch((error) => {
        console.error(error);
      });
  },
};
</script>
