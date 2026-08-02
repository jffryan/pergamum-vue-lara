import { makeRequest, buildUrl } from "./apiHelpers";

// GET ALL
const getAllVersions = async (options = {}) =>
    makeRequest("get", buildUrl("versions"), null, options);

// CREATE
const createVersion = async (version) =>
    makeRequest("post", buildUrl("versions"), { version });

// DISCARD — pass null (or omit) when the date we got rid of it is unknown
const discardVersion = async (version_id, discarded_at = null) =>
    makeRequest("patch", buildUrl("versions", `${version_id}/discard`), {
        discarded_at,
    });

// RESTORE — we have the copy again
const restoreVersion = async (version_id) =>
    makeRequest("patch", buildUrl("versions", `${version_id}/restore`));

export { getAllVersions, createVersion, discardVersion, restoreVersion };
