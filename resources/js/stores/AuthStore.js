import { defineStore } from "pinia";
import axios from "axios";
import router from "@/router";

const useAuthStore = defineStore("AuthStore", {
    state: () => ({
        user: null,
        authChecked: false,
    }),
    getters: {
        isLoggedIn: (state) => !!state.user,
    },
    actions: {
        async fetchUser() {
            try {
                const res = await axios.get("/api/user");
                this.user = res.data;
            } catch {
                this.user = null;
            } finally {
                this.authChecked = true;
            }
        },
        async logout() {
            await axios.post("/logout");
            this.authChecked = false;
            this.user = null;
            router.push({ name: "home" });
        },
    },
});

export default useAuthStore;