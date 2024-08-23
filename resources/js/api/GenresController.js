import { makeRequest, buildUrl } from "./apiHelpers";

const getAllGenres = async (options = {}) =>
    makeRequest("get", buildUrl("genres"), null, options);

const getOneGenre = async (genre_id, options = {}) =>
    makeRequest("get", buildUrl(`genres/${genre_id}`), null, options);

export { getAllGenres, getOneGenre };
