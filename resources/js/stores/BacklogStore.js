import { defineStore } from "pinia";
import axios from "axios";

const useBacklogStore = defineStore("BacklogStore", {
    state: () => ({
        backlog: null,
        isnot_complete: true,
    }),
    actions: {
        setBacklog(backlog) {
            this.backlog = backlog;
        },
        async updateBacklogOrdinals() {
            await axios.post("/api/backlog/update-ordinals", {
                items: this.backlog.incompleteItems,
            });
        },
    },
});

export default useBacklogStore;
