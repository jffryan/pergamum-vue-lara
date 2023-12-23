<template>
  <div>
    <h1>Backlog</h1>
    <div>
      <BacklogControls class="w-1/2 mb-8" />
    </div>
    <div>
      <BookshelfTable :books="backlogBooks" bookshelfTitle="All" />
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
    backlogBooks() {
      return this.BacklogStore.activeBacklog;
    },
  },
  mounted() {
    this.BacklogStore.fetchAndSetBacklog();
  },
};
</script>
