<template>
  <div class="body-container">
    <div class="flex justify-between items-center mt-4 py-4 px-20">
      <div class="flex items-center">
        <!-- <img src="/images/pergamum-logo.svg" alt="Pergamum" /> -->
      </div>
      <div>
        <router-link :to="{ name: 'about' }" class="mr-8">About</router-link>
        <button class="btn btn-primary mr-4">Login</button>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  name: "HeaderNav",
};
</script>
