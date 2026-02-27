<script setup>
import { useAuthStore } from "@/stores";
const authStore = useAuthStore();
const emit = defineEmits(["hamburger-click"]);
</script>

<template>
    <div class="bg-slate-900 sticky top-0 z-40 w-full flex-none shadow-md">
        <div class="max-w-8xl mx-auto">
            <div class="py-3 px-4 sm:px-6 md:px-8">
                <div class="flex items-center justify-between">

                    <!-- Left: hamburger + logo -->
                    <div class="flex items-center gap-3">
                        <button
                            v-if="authStore.isLoggedIn"
                            @click="emit('hamburger-click')"
                            class="lg:hidden text-slate-300 hover:text-white transition-colors p-1 -ml-1 text-xl leading-none"
                            aria-label="Open menu"
                        >
                            ☰
                        </button>
                        <router-link
                            to="/"
                            class="font-bold text-white text-lg tracking-tight hover:text-slate-300 transition-colors"
                        >
                            Pergamum
                        </router-link>
                    </div>

                    <!-- Right: nav links + actions -->
                    <div class="flex items-center gap-5">
                        <router-link
                            v-if="authStore.isLoggedIn"
                            :to="{ name: 'admin.home' }"
                            class="hidden lg:inline text-sm text-slate-400 hover:text-white transition-colors"
                        >
                            Admin
                        </router-link>
                        <router-link
                            :to="{ name: 'about' }"
                            class="text-sm text-slate-400 hover:text-white transition-colors"
                        >
                            About
                        </router-link>
                        <button
                            v-if="authStore.isLoggedIn"
                            @click="authStore.logout"
                            class="text-sm text-slate-400 hover:text-white transition-colors"
                        >
                            Logout
                        </button>
                        <router-link
                            v-if="!authStore.isLoggedIn"
                            :to="{ name: 'login' }"
                            class="bg-white text-slate-900 rounded-md px-3 py-1.5 text-sm font-semibold hover:bg-slate-100 transition-colors"
                        >
                            Login
                        </router-link>
                        <router-link
                            v-else
                            :to="{ name: 'dashboard' }"
                            class="bg-white text-slate-900 rounded-md px-3 py-1.5 text-sm font-semibold hover:bg-slate-100 transition-colors"
                        >
                            Dashboard
                        </router-link>
                    </div>

                </div>
            </div>
        </div>
    </div>
</template>
