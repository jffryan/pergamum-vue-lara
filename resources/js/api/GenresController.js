import { makeRequest, buildUrl } from "./apiHelpers";

const getAllGenres = async (options = {}) =>
    makeRequest("get", buildUrl("genres"), null, options);

const getOneGenre = async (genre_id, options = {}) =>
    makeRequest("get", buildUrl(`genres/${genre_id}`), null, options);

const createGenre = async (name) =>
    makeRequest("post", buildUrl("genres"), { name });

const updateGenre = async (genre_id, name) =>
    makeRequest("patch", buildUrl("genres", genre_id), { name });

// `force` is the server-side acknowledgement that this detaches the genre from
// every book still using it. Without it the API 409s and reports the count.
const deleteGenre = async (genre_id, { force = false } = {}) =>
    makeRequest(
        "delete",
        buildUrl("genres", genre_id),
        null,
        force ? { force: true } : {},
    );

// `keep_id` is the winner; `source_ids` are folded into it and deleted.
const mergeGenres = async (keep_id, source_ids) =>
    makeRequest("post", buildUrl("genres", keep_id, "merge"), { source_ids });

export {
    getAllGenres,
    getOneGenre,
    createGenre,
    updateGenre,
    deleteGenre,
    mergeGenres,
};
