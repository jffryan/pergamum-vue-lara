import { defineStore } from "pinia";
import axios from "axios";

const useBacklogStore = defineStore("BacklogStore", {
  state: () => ({
    backlog: null,
    activeBacklog: null,
    filters: {},
  }),
  actions: {
    setActiveBacklog(backlog) {
      this.activeBacklog = backlog;
    },
    fetchAndSetBacklog() {
      axios
        .get("/api/backlog")
        .then((response) => {
          console.log(response.data);
          this.backlog = response.data.data;
          this.setActiveBacklog(this.backlog);
        })
        .catch((error) => {
          console.log(error);
        });
    },

    // Filters
    setFilter(filterName, value) {
      this.filters[filterName] = value;
    },
    resetFilters() {
      this.filters = {};
    },
    toggleFilter(filterName) {
      if (this.prototype.hasOwnProperty.call(this.filters, filterName)) {
        this.filters[filterName] = !this.filters[filterName];
      } else {
        this.setFilter(filterName, true); // Default to true if the filter doesn't exist
      }
    },
  },
});

export default useBacklogStore;
