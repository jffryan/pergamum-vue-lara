<template>
    <div>
        <h1 class="text-2xl font-bold mb-4">My Lists</h1>
        <div v-if="isLoading">
            <PageLoadingIndicator />
        </div>
        <div v-else-if="showErrorMessage">
            <AlertBox :message="error" alert-type="danger" />
        </div>
        <div v-else>
            <form @submit.prevent="createNewList" class="flex flex-col sm:flex-row gap-2 mb-6">
                <input
                    v-model="newListName"
                    type="text"
                    placeholder="New list name..."
                    class="flex-1 bg-zinc-50 border border-gray-400 rounded px-2 py-1"
                    required
                />
                <button
                    type="submit"
                    class="bg-slate-900 text-white rounded px-3 py-2 hover:bg-slate-700 sm:py-1"
                    :disabled="isCreating"
                >
                    Create List
                </button>
            </form>
            <div v-if="allLists.length === 0" class="text-gray-500">
                No lists yet. Create one above.
            </div>
            <ul v-else class="divide-y divide-gray-200 border border-gray-200 rounded">
                <li
                    v-for="list in allLists"
                    :key="list.list_id"
                >
                    <router-link
                        :to="{ name: 'lists.show', params: { id: list.list_id } }"
                        class="block px-4 py-3 hover:bg-slate-50 hover:underline"
                    >
                        {{ list.name }}
                    </router-link>
                </li>
            </ul>
        </div>
    </div>
</template>

<script>
import { getAllLists, createList } from "@/api/ListController";

import { useListsStore } from "@/stores";

import AlertBox from "@/components/globals/alerts/AlertBox.vue";
import PageLoadingIndicator from "@/components/globals/loading/PageLoadingIndicator.vue";

export default {
    name: "ListsView",
    components: {
        AlertBox,
        PageLoadingIndicator,
    },
    setup() {
        const ListsStore = useListsStore();
        return { ListsStore };
    },
    data() {
        return {
            isLoading: true,
            showErrorMessage: false,
            error: "",
            newListName: "",
            isCreating: false,
        };
    },
    computed: {
        allLists() {
            return this.ListsStore.allLists;
        },
    },
    methods: {
        async createNewList() {
            if (!this.newListName.trim()) return;
            this.isCreating = true;
            try {
                const res = await createList(this.newListName.trim());
                this.ListsStore.addList(res.data);
                this.newListName = "";
            } catch (error) {
                console.error("Error creating list:", error);
                this.showErrorMessage = true;
                this.error = "Unable to create list. Please try again.";
            } finally {
                this.isCreating = false;
            }
        },
    },
    async mounted() {
        try {
            const res = await getAllLists();
            this.ListsStore.setAllLists(res.data);
        } catch (error) {
            console.error("Error fetching lists:", error);
            this.showErrorMessage = true;
            this.error = "Unable to load lists at this time. Please try again later.";
        } finally {
            this.isLoading = false;
        }
    },
};
</script>
