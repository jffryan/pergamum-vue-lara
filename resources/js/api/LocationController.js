import { makeRequest, buildUrl } from "./apiHelpers";

// Locations route by slug ('o1s5'), not id — see Location::getRouteKeyName.

const getAllLocations = async (options = {}) =>
    makeRequest("get", buildUrl("locations"), null, options);

const getOneLocation = async (slug, options = {}) =>
    makeRequest("get", buildUrl("locations", slug), null, options);

// The nested `/books` path is hand-built, as in ListController.
const getLocationBooks = async (slug, options = {}) =>
    makeRequest("get", buildUrl("locations", slug, "books"), null, options);

const createLocation = async (attributes) =>
    makeRequest("post", buildUrl("locations"), attributes);

const updateLocation = async (slug, attributes) =>
    makeRequest("patch", buildUrl("locations", slug), attributes);

// `force` acknowledges that this unshelves every copy on the location. A
// location with child locations refuses regardless — move them first.
const deleteLocation = async (slug, { force = false } = {}) =>
    makeRequest(
        "delete",
        buildUrl("locations", slug),
        null,
        force ? { force: true } : {},
    );

// Shelve, reshelve, or unshelve one copy. `location_id: null` unshelves, so
// the key is always sent explicitly.
const setVersionLocation = async (
    version_id,
    location_id,
    shelf_ordinal = null,
) =>
    makeRequest("patch", buildUrl("versions", version_id, "location"), {
        location_id,
        shelf_ordinal,
    });

export {
    getAllLocations,
    getOneLocation,
    getLocationBooks,
    createLocation,
    updateLocation,
    deleteLocation,
    setVersionLocation,
};
