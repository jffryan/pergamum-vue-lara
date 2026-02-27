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

        <button
            :disabled="!selectedFile || loading"
            @click="submit"
            class="btn bg-zinc-700 text-white disabled:opacity-50"
        >
            {{ loading ? "Uploading..." : "Upload" }}
        </button>

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
            error: null,
        };
    },
    methods: {
        onFileChange(event) {
            this.selectedFile = event.target.files[0] || null;
            this.summary = null;
            this.results = [];
            this.error = null;
        },
        async submit() {
            if (!this.selectedFile) return;
            this.loading = true;
            this.error = null;
            this.summary = null;
            this.results = [];
            try {
                const response = await bulkUpload(this.selectedFile);
                this.summary = response.data.summary;
                this.results = response.data.results;
            } catch (err) {
                this.error =
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
