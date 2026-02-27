<template>
    <div>
        <HeaderNav @hamburger-click="drawerOpen = true" />

        <div class="relative max-w-8xl mx-auto px-4 sm:px-6 md:px-8">

        <!-- Mobile drawer backdrop -->
        <Transition
            enter-from-class="opacity-0"
            enter-active-class="transition-opacity duration-200"
            leave-to-class="opacity-0"
            leave-active-class="transition-opacity duration-200"
        >
            <div
                v-if="drawerOpen"
                class="fixed inset-0 z-40 bg-black/50 lg:hidden"
                @click="drawerOpen = false"
            />
        </Transition>

        <!-- Mobile drawer -->
        <div
            v-if="authStore.isLoggedIn"
            class="fixed inset-y-0 left-0 z-50 w-56 bg-white shadow-xl transform transition-transform duration-200 ease-in-out lg:hidden"
            :class="drawerOpen ? 'translate-x-0' : '-translate-x-full'"
        >
            <div class="pt-16 px-4 py-6">
                <SidebarNav />
            </div>
        </div>

        <div class="flex">
            <!-- Desktop static sidebar -->
            <SidebarNav
                v-if="authStore.isLoggedIn"
                class="hidden lg:block z-20 w-[10rem] py-6 pr-8 overflow-y-auto shrink-0"
            />
            <RouterView :class="['pt-6 pb-16 w-full', authStore.isLoggedIn ? 'lg:pl-40' : '']" />
        </div>

        </div><!-- end constrained wrapper -->
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
    data() {
        return {
            drawerOpen: false,
        };
    },
    watch: {
        $route() {
            this.drawerOpen = false;
        },
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
