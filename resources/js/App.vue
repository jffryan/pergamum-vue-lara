<template>
    <HeaderNav />
    <div class="relative max-w-8xl mx-auto px-4 sm:px-6 md:px-8">
        <SidebarNav
            class="fixed z-20 inset-0 top-16 left-[max(0px,calc(50%-45rem))] right-auto w-[20rem] py-6 pr-8 overflow-y-auto"
        />
        <div>
            <RouterView class="pt-6 pb-16 pl-[20rem] w-full" />
        </div>
    </div>
</template>

<script>
import HeaderNav from "@/components/navs/HeaderNav.vue";
import SidebarNav from "@/components/navs/SidebarNav.vue";

export default {
    name: "App",
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
