import { defineStore } from "pinia";
import axios from "axios";

// Deliberately does not import the router. `@/router` imports `@/stores` for
// its navigation guard, so a store reaching back for the router singleton
// closes a cycle (router → stores → AuthStore → router). Navigation is the
// caller's job — components have `useRouter()` for it.
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
        },
    },
});

export default useAuthStore;
