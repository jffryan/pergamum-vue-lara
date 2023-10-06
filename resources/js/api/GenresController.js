import { makeRequest, buildUrl } from "./apiHelpers";

const getAllGenres = async (options = {}) =>
  makeRequest("get", buildUrl("genres"), null, options);

const getOneGenre = async (genre_id) =>
  makeRequest("get", buildUrl(`genres/${genre_id}`));

export { getAllGenres, getOneGenre };
