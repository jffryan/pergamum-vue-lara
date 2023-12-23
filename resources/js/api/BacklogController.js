import { makeRequest, buildUrl } from "./apiHelpers";

// GET ALL
const getAllBacklog = async (options = {}) =>
  makeRequest("get", buildUrl("books"), null, options);

export { getAllBacklog };
