import { defineStore } from "pinia";
import { makeRequest, buildUrl } from "@/api/apiHelpers";

const useConfigStore = defineStore("ConfigStore", {
    state: () => ({
        books: {
            formats: [],
        },
    }),
    actions: {
        async checkForFormats() {
            if (this.books.formats.length === 0) {
                await this.setFormats();
            }
        },
        async setFormats() {
            try {
                const response = await makeRequest(
                    "get",
                    buildUrl("config/formats"),
                );
                this.books.formats = response.data;
            } catch (error) {
                console.log(error);
            }
        },
        async createFormat(name, capabilities = {}) {
            const response = await makeRequest("post", buildUrl("formats"), {
                name,
                ...capabilities,
            });
            // Refetch rather than push the created row: POST returns the whole
            // model, the cache holds the /config/formats projection, and the
            // forms read capability flags off whatever shape is in here.
            await this.setFormats();
            return response.data;
        },
    },
});

export default useConfigStore;
