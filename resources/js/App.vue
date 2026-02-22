<template>
    <div class="relative max-w-8xl mx-auto px-4 sm:px-6 md:px-8">
        <HeaderNav />
        <div class="flex">
            <SidebarNav v-if="authStore.isLoggedIn"
                class="z-20 inset-0 top-16 left-[max(0px,calc(50%-45rem))] right-auto w-[10rem] py-6 pr-8 overflow-y-auto" />
            <RouterView :class="['pt-6 pb-16 w-full', authStore.isLoggedIn ? 'pl-40' : '']" />

        </div>

    </div>
</template>

<script>
import { useAuthStore } from "@/stores";
import HeaderNav from "@/components/navs/HeaderNav.vue";
import SidebarNav from "@/components/navs/SidebarNav.vue";

export default {
    name: "App",
    setup() {
        const authStore = useAuthStore();
        return { authStore };
    },
    components: {
        HeaderNav,
        SidebarNav,
    },
};
</script>

<style scoped>
.body-grid {
    display: grid;
    grid-template-columns: 1fr 5fr;
    min-height: 100vh;
}

.body-container {
    max-width: 1440px;
    margin: 0 auto;
}
</style>
