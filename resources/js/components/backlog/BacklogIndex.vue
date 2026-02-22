<template>
    <div>
        <h1>Backlog</h1>
        <div>
            <BacklogControls class="w-1/2 mb-8" />
        </div>
        <div>
            <BookshelfTable
                v-if="BacklogStore.isnot_complete"
                :books="BacklogStore.backlog.incompleteItems"
                :bookshelfTitle="null"
                :isSortable="true"
                @update:books="updateBacklogOrder"
                class="mb-12"
            />
            <BookshelfTable
                v-if="!BacklogStore.isnot_complete"
                :books="completedBacklog"
                bookshelfTitle="Completed"
                class="mb-12"
            />
        </div>
    </div>
</template>

<script>
import { useBacklogStore } from "@/stores";

import BacklogControls from "@/components/backlog/BacklogControls.vue";
import BookshelfTable from "@/components/books/table/BookshelfTable.vue";

export default {
    name: "BacklogHome",
    components: {
        BacklogControls,
        BookshelfTable,
    },
    setup() {
        const BacklogStore = useBacklogStore();

        return {
            BacklogStore,
        };
    },
    computed: {
        currentBacklog() {
            return this.BacklogStore.backlog.incompleteItems;
        },
        completedBacklog() {
            return this.BacklogStore.backlog.completedItems;
        },
    },
    methods: {
        updateBacklogOrder(newOrder) {
            console.log("UPDATE BOOKS");
            console.log(newOrder[0]);
            this.BacklogStore.backlog.incompleteItems = newOrder;
            console.log("BACKLOG STORE");
            console.log(this.BacklogStore.backlog.incompleteItems[0]);
        },
    },
};
</script>
