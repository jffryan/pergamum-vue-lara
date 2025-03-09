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
            try {
                const updatedItems = this.backlog.incompleteItems.map(
                    (book, index) => ({
                        backlog_item_id: book.backlog_item_id,
                        ordinal: index + 1, // Assigning new ordinals
                    }),
                );

                await axios.post("/api/backlog/update-ordinals", {
                    items: updatedItems,
                });

                console.log("Backlog order updated successfully.");
            } catch (error) {
                console.error("Failed to update backlog order:", error);
            }
        },
    },
});

export default useBacklogStore;
