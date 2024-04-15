<template>
  <div
    class="sticky top-0 z-40 w-full flex-none transition-colors duration-500 lg:z-50"
  >
    <div class="max-w-8xl mx-auto">
      <div class="py-4 mx-4 lg:mx-0">
        <div class="relative flex items-center justify-between">
          <router-link to="/" class="font-bold">Pergamum</router-link>
          <div>
            <router-link :to="{ name: 'backlog.home' }" class="mr-8"
              >Backlog Challenge</router-link
            >
            <router-link :to="{ name: 'about' }" class="mr-8"
              >About</router-link
            >
            <button class="btn btn-primary mr-4">Login</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script>
export default {
  name: "HeaderNav",
};
</script>
