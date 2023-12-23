<template>
  <div>
    <h1>Backlog Challenge</h1>
    <router-link :to="{ name: 'backlog.index' }"
      >View Remaining Backlog</router-link
    >
  </div>
</template>

<script>
export default {
  name: "BacklogHome",
};
</script>
