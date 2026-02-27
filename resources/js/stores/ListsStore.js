import { defineStore } from "pinia";

const useListsStore = defineStore("ListsStore", {
    state: () => ({
        allLists: [],
        currentList: null,
    }),
    actions: {
        setAllLists(lists) {
            this.allLists = lists;
        },
        setCurrentList(list) {
            this.currentList = list;
        },
        addList(list) {
            this.allLists.push(list);
        },
        updateList(list) {
            const index = this.allLists.findIndex((l) => l.list_id === list.list_id);
            if (index !== -1) this.allLists[index] = list;
            if (this.currentList?.list_id === list.list_id) {
                this.currentList = { ...this.currentList, ...list };
            }
        },
        removeList(listId) {
            this.allLists = this.allLists.filter((l) => l.list_id !== listId);
            if (this.currentList?.list_id === listId) {
                this.currentList = null;
            }
        },
    },
});

export default useListsStore;
