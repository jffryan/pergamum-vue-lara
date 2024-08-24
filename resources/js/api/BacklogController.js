import { makeRequest, buildUrl } from "./apiHelpers";

// GET ALL
const fetchBacklog = async (options = {}) =>
    makeRequest("get", buildUrl("backlog"), null, options);

export { fetchBacklog };
