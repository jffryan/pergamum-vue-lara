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

// LEND — both details optional. Only the keys passed are sent, because the
// server keeps an omitted key's existing value on a re-lend and clears on null.
const lendVersion = async (version_id, details = {}) =>
    makeRequest("patch", buildUrl("versions", `${version_id}/lend`), details);

// RETURN — the copy is back; its shelf never changed
const returnVersion = async (version_id) =>
    makeRequest("patch", buildUrl("versions", `${version_id}/return`));

export {
    getAllVersions,
    createVersion,
    discardVersion,
    restoreVersion,
    lendVersion,
    returnVersion,
};
