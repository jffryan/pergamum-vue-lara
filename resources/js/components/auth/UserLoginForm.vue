<script setup>
import { ref } from "vue";
import { useRoute, useRouter } from "vue-router";
import { useAuthStore } from "@/stores";
import axios from "axios";
import BaseForm from "@/components/ui/forms/BaseForm.vue";

const route = useRoute();
const router = useRouter();
const AuthStore = useAuthStore();

const form = ref({
    email: "",
    password: "",
});

const error = ref(null);

const login = async () => {
    error.value = null;
    try {
        await axios.get("/sanctum/csrf-cookie"); // ensures CSRF cookie is set
        await axios.post("/login", form.value);
        await AuthStore.fetchUser();
        const redirectTo = route.query.redirect || "/dashboard";
        router.push(redirectTo);
    } catch (err) {
        error.value = err.response?.data?.message || "Login failed";
    }
};
</script>

<template>
    <base-form :on-submit="login">
        <template #title>
            <h1 class="text-2xl font-bold mb-4">Login</h1>
        </template>

        <input
            v-model="form.email"
            type="email"
            placeholder="Email"
            required
            class="input"
        />

        <input
            v-model="form.password"
            type="password"
            placeholder="Password"
            required
            class="input"
        />

        <button type="submit" class="btn">Login</button>

        <p v-if="error" class="text-red-500 mt-2">{{ error }}</p>
    </base-form>
</template>