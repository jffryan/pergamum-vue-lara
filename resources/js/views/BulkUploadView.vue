<template>
    <div class="lg:w-2/3 px-6 py-8">
        <h1>Bulk Upload</h1>

        <div class="mb-4">
            <label class="block mb-1 font-medium">CSV File</label>
            <input
                type="file"
                accept=".csv"
                @change="onFileChange"
                class="block"
            />
        </div>

        <div class="mb-4">
            <label class="flex items-center gap-2">
                <input type="checkbox" v-model="addToList" />
                <span>Add everything imported to a new list</span>
            </label>

            <input
                v-if="addToList"
                v-model="listName"
                type="text"
                maxlength="255"
                placeholder="New list name"
                class="mt-2 block border border-zinc-400 px-2 py-1"
            />
        </div>

        <button
            :disabled="submitDisabled"
            @click="submit"
            class="btn bg-zinc-700 text-white disabled:opacity-50"
        >
            {{ loading ? "Uploading..." : "Upload" }}
        </button>

        <p v-if="list" class="mt-6">
            <template v-if="list.list_id">
                Added {{ list.items_added }}
                {{ list.items_added === 1 ? "item" : "items" }} to
                <router-link
                    :to="{ name: 'lists.show', params: { id: list.list_id } }"
                    class="underline"
                >
                    {{ list.name }}
                </router-link>
            </template>
            <template v-else>
                No list was created — no rows succeeded.
            </template>
        </p>

        <div v-if="summary" class="mt-6">
            <p class="font-medium mb-2">
                {{ summary.succeeded }} succeeded,
                {{ summary.skipped }} skipped,
                {{ summary.failed }} failed
                ({{ summary.total }} total rows)
            </p>

            <div class="overflow-auto max-h-96">
                <table class="w-full text-sm border-collapse">
                    <thead>
                        <tr class="bg-zinc-400">
                            <th class="border border-zinc-500 px-2 py-1 text-left">Row</th>
                            <th class="border border-zinc-500 px-2 py-1 text-left">Title</th>
                            <th class="border border-zinc-500 px-2 py-1 text-left">Status</th>
                            <th class="border border-zinc-500 px-2 py-1 text-left">Reason</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="result in results"
                            :key="result.row"
                            :class="rowClass(result.status)"
                        >
                            <td class="border border-zinc-400 px-2 py-1">{{ result.row }}</td>
                            <td class="border border-zinc-400 px-2 py-1">{{ result.title }}</td>
                            <td class="border border-zinc-400 px-2 py-1 capitalize">{{ result.status }}</td>
                            <td class="border border-zinc-400 px-2 py-1">{{ result.reason || "" }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <p v-if="error" class="mt-4 text-red-700">{{ error }}</p>
    </div>
</template>

<script>
import { bulkUpload } from "@/api/BulkUploadApi";

export default {
    name: "BulkUploadView",
    data() {
        return {
            selectedFile: null,
            loading: false,
            summary: null,
            results: [],
            list: null,
            addToList: false,
            listName: "",
            error: null,
        };
    },
    computed: {
        submitDisabled() {
            if (!this.selectedFile || this.loading) return true;
            return this.addToList && !this.listName.trim();
        },
    },
    methods: {
        clearResults() {
            this.summary = null;
            this.results = [];
            this.list = null;
            this.error = null;
        },
        onFileChange(event) {
            this.selectedFile = event.target.files[0] || null;
            this.clearResults();
        },
        async submit() {
            if (this.submitDisabled) return;
            this.loading = true;
            this.clearResults();
            try {
                const response = await bulkUpload(this.selectedFile, {
                    listName: this.addToList ? this.listName.trim() : null,
                });
                this.summary = response.data.summary;
                this.results = response.data.results;
                this.list = response.data.list;
            } catch (err) {
                // Whole-file rejections use {reason_code, reason}; Laravel's own
                // request validation uses {message, errors}. Read both.
                this.error =
                    err.response?.data?.reason ||
                    err.response?.data?.message ||
                    "An error occurred during upload.";
            } finally {
                this.loading = false;
            }
        },
        rowClass(status) {
            if (status === "success") return "bg-green-100";
            if (status === "skipped") return "bg-yellow-100";
            if (status === "failed") return "bg-red-100";
            return "";
        },
    },
};
</script>
