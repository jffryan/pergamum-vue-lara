import { makeRequest, buildUrl } from "./apiHelpers";

const getAllLists = async () => makeRequest("get", buildUrl("lists"));

const getOneList = async (listId) => makeRequest("get", buildUrl("lists", listId));

const createList = async (name) => makeRequest("post", buildUrl("lists"), { name });

const updateList = async (listId, name) =>
    makeRequest("patch", buildUrl("lists", listId), { name });

const deleteList = async (listId) => makeRequest("delete", buildUrl("lists", listId));

const reorderList = async (listId, items) =>
    makeRequest("patch", `/api/lists/${listId}/reorder`, { items });

const addItemToList = async (listId, versionId) =>
    makeRequest("post", `/api/lists/${listId}/items`, { version_id: versionId });

const removeItemFromList = async (listId, itemId) =>
    makeRequest("delete", `/api/lists/${listId}/items/${itemId}`);

export {
    getAllLists,
    getOneList,
    createList,
    updateList,
    deleteList,
    reorderList,
    addItemToList,
    removeItemFromList,
};
