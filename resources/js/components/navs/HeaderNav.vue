<script setup>
import { useAuthStore } from "@/stores";
const authStore = useAuthStore();
</script>


<template>
    <div
        class="bg-zinc-50 sticky top-0 z-40 w-full flex-none transition-colors duration-500 lg:z-50"
    >
        <div class="max-w-8xl mx-auto">
            <div class="py-4 mx-4 lg:mx-0">
                <div class="relative flex items-center justify-between">
                    <router-link to="/" class="font-bold">Pergamum</router-link>
                    <div>
                        <router-link v-if="authStore.isLoggedIn" :to="{ name: 'admin.home'}" class="mr-4">Admin</router-link>
                        <router-link :to="{ name: 'about' }" class="mr-8"
                            >About</router-link
                        >
                        <button v-if="authStore.isLoggedIn" @click="authStore.logout" class="btn btn-secondary mr-4">Logout</button>
                        <router-link v-if="!authStore.isLoggedIn" :to="{ name: 'login' }" class="btn btn-primary">Login</router-link>
                        <router-link v-else :to="{ name: 'dashboard' }" class="btn btn-primary">Dashboard</router-link>                        
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>