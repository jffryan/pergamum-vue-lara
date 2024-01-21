import { defineStore } from "pinia";
import axios from "axios";

const useBacklogStore = defineStore("BacklogStore", {
  state: () => ({
    backlog: null,
    isnot_complete: true,
  }),
  actions: {
    setActiveBacklog(backlog) {
      this.activeBacklog = backlog;
    },
    fetchAndSetBacklog() {
      axios
        .get("/api/backlog")
        .then((response) => {
          this.backlog = response.data;
        })
        .catch((error) => {
          console.log(error);
        });
    },
    async updateBacklogOrdinals() {
      await axios.post("/api/backlog/update-ordinals", {
        items: this.backlog.incompleteItems,
      });
    },
  },
});

export default useBacklogStore;
