import { makeRequest, buildUrl } from "./apiHelpers";

// GET ALL
const getAllVersions = async (options = {}) =>
    makeRequest("get", buildUrl("versions"), null, options);

// CREATE
const createVersion = async (version) =>
    makeRequest("post", buildUrl("versions"), { version });

export { getAllVersions, createVersion };
