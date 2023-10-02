import { makeRequest, buildUrl } from "./apiHelpers";

// GET ONE
const getOneAuthor = async (author_id) =>
  makeRequest("get", buildUrl("authors", author_id));

const getAuthorBySlug = async (slug) =>
  makeRequest("get", buildUrl("author", slug));

export { getOneAuthor, getAuthorBySlug };
