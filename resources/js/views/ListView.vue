<template>
    <div>
        <div v-if="isLoading">
            <PageLoadingIndicator />
        </div>
        <div v-else-if="showErrorMessage">
            <AlertBox :message="error" alert-type="danger" />
        </div>
        <div v-else>
            <!-- Header: list name + actions -->
            <div class="mb-3">
                <div v-if="isEditing" class="flex flex-col sm:flex-row gap-2">
                    <input
                        v-model="editName"
                        type="text"
                        class="flex-1 bg-zinc-50 border border-gray-400 rounded px-2 py-1"
                        @keyup.enter="saveRename"
                        @keyup.escape="cancelRename"
                    />
                    <div class="flex gap-2">
                        <button
                            @click="saveRename"
                            class="bg-slate-900 text-white rounded px-3 py-1 hover:bg-slate-700"
                        >
                            Save
                        </button>
                        <button
                            @click="cancelRename"
                            class="border border-gray-400 rounded px-3 py-1 hover:bg-slate-100"
                        >
                            Cancel
                        </button>
                    </div>
                </div>
                <div v-else>
                    <h1 class="text-2xl font-bold mb-1">{{ list.name }}</h1>
                    <div class="flex gap-4">
                        <button
                            @click="startRename"
                            class="text-sm text-gray-500 hover:underline"
                        >
                            Rename
                        </button>
                        <button
                            @click="confirmDelete"
                            class="text-sm text-red-600 hover:underline"
                        >
                            Delete list
                        </button>
                    </div>
                </div>
            </div>

            <div class="flex gap-4 mb-4">
                <router-link
                    :to="{ name: 'lists.index' }"
                    class="text-sm text-gray-500 hover:underline"
                >
                    ← Back to Lists
                </router-link>
                <router-link
                    :to="{
                        name: 'lists.statistics',
                        params: { id: list.list_id },
                    }"
                    class="text-sm text-gray-500 hover:underline"
                >
                    Statistics →
                </router-link>
            </div>

            <ListItemsTable
                :items="list.items"
                :show-remove="true"
                @remove="removeItem"
            />

            <AddBookSearch
                class="mt-6"
                :is-version-added="isVersionAdded"
                @add="addVersion"
            />
        </div>
    </div>
</template>

<script>
import {
    getOneList,
    updateList,
    deleteList,
    removeItemFromList,
    addItemToList,
} from "@/api/ListController";

import { useListsStore, useStatisticsStore } from "@/stores";

import AddBookSearch from "@/components/books/AddBookSearch.vue";
import AlertBox from "@/components/globals/alerts/AlertBox.vue";
import ListItemsTable from "@/components/lists/ListItemsTable.vue";
import PageLoadingIndicator from "@/components/globals/loading/PageLoadingIndicator.vue";

export default {
    name: "ListView",
    components: {
        AddBookSearch,
        AlertBox,
        ListItemsTable,
        PageLoadingIndicator,
    },
    setup() {
        const ListsStore = useListsStore();
        const StatisticsStore = useStatisticsStore();
        return { ListsStore, StatisticsStore };
    },
    data() {
        return {
            isLoading: true,
            showErrorMessage: false,
            error: "",
            list: null,
            isEditing: false,
            editName: "",
        };
    },
    computed: {
        addedVersionIds() {
            return new Set(this.list.items.map((i) => i.version_id));
        },
    },
    methods: {
        async fetchList() {
            this.isLoading = true;
            const listId = this.$route.params.id;
            try {
                const res = await getOneList(listId);
                if (!res.data || res.status !== 200) {
                    throw new Error("Failed to fetch list");
                }
                this.list = res.data;
                this.ListsStore.setCurrentList(res.data);
            } catch (error) {
                console.error("Error fetching list:", error);
                this.showErrorMessage = true;
                this.error =
                    "Unable to load this list. Please try again later.";
            } finally {
                this.isLoading = false;
            }
        },
        startRename() {
            this.editName = this.list.name;
            this.isEditing = true;
        },
        cancelRename() {
            this.isEditing = false;
            this.editName = "";
        },
        async saveRename() {
            if (!this.editName.trim()) return;
            try {
                const res = await updateList(
                    this.list.list_id,
                    this.editName.trim(),
                );
                this.list.name = res.data.name;
                this.list.slug = res.data.slug;
                this.ListsStore.updateList(res.data);
                this.isEditing = false;
            } catch (error) {
                console.error("Error renaming list:", error);
            }
        },
        async confirmDelete() {
            // Suppressed rather than fixed: the real fix is to route this
            // through `components/globals/ConfirmAction.vue` like the genre
            // admin deletes do, which is a UI change rather than a lint one.
            // Tracked in `/feature-plans/lists.md`.
            // eslint-disable-next-line no-alert, no-restricted-globals
            if (!confirm(`Delete "${this.list.name}"? This cannot be undone.`))
                return;
            try {
                await deleteList(this.list.list_id);
                this.ListsStore.removeList(this.list.list_id);
                this.$router.push({ name: "lists.index" });
            } catch (error) {
                console.error("Error deleting list:", error);
            }
        },
        async removeItem(item) {
            try {
                await removeItemFromList(this.list.list_id, item.list_item_id);
                this.list.items = this.list.items.filter(
                    (i) => i.list_item_id !== item.list_item_id,
                );
                this.StatisticsStore.invalidate("list", this.list.list_id);
            } catch (error) {
                console.error("Error removing item from list:", error);
            }
        },
        isVersionAdded(version) {
            return this.addedVersionIds.has(version.version_id);
        },
        async addVersion(version) {
            try {
                const res = await addItemToList(
                    this.list.list_id,
                    version.version_id,
                );
                this.list.items.push(res.data);
                this.StatisticsStore.invalidate("list", this.list.list_id);
            } catch (error) {
                console.error("Error adding item to list:", error);
            }
        },
    },
    watch: {
        "$route.params.id": {
            immediate: true,
            handler: "fetchList",
        },
    },
};
</script>
