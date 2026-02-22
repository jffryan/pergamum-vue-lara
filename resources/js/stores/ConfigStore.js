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
        async createFormat(name) {
            const response = await makeRequest("post", buildUrl("formats"), {
                name,
            });
            this.books.formats.push(response.data);
            return response.data;
        },
    },
});

export default useConfigStore;
