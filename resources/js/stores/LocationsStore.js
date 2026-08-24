import { defineStore } from "pinia";
import {
    getAllLocations,
    getOneLocation,
    createLocation as createLocationRequest,
    updateLocation as updateLocationRequest,
    deleteLocation as deleteLocationRequest,
    setVersionLocation as setVersionLocationRequest,
} from "@/api/LocationController";

const useLocationsStore = defineStore("LocationsStore", {
    state: () => ({
        // The whole tree, flat — ~24 rows, fetched once. Hierarchy is
        // derived from parent_id by the getters below.
        allLocations: [],
        // The show payload for the location currently on screen:
        // { location, ancestors, children, subtree_versions_count }.
        currentLocation: null,
    }),
    getters: {
        roots: (state) =>
            state.allLocations.filter((location) => !location.parent_id),
        childrenOf: (state) => (location_id) =>
            state.allLocations.filter(
                (location) => location.parent_id === location_id,
            ),
        bySlug: (state) => (slug) =>
            state.allLocations.find((location) => location.slug === slug) ??
            null,
        /**
         * 'Office / O1 / O1S5' — the location prefixed by its ancestors, for
         * selects where a bare code doesn't say where the place is. Walks the
         * loaded tree, so it needs `fetchAllLocations` to have run.
         */
        pathLabel: (state) => (location) => {
            const byId = new Map(
                state.allLocations.map((candidate) => [
                    candidate.location_id,
                    candidate,
                ]),
            );

            const parts = [];
            let current = location;

            while (current) {
                parts.unshift(current.name || current.code);
                current = byId.get(current.parent_id);
            }

            return parts.join(" / ");
        },
        /**
         * Every id in a location's subtree, itself included — what a move
         * target must not be, and what a rollup count sums over.
         */
        subtreeIdsOf: (state) => (location_id) => {
            const ids = [location_id];

            for (let i = 0; i < ids.length; i += 1) {
                state.allLocations
                    .filter((location) => location.parent_id === ids[i])
                    .forEach((location) => ids.push(location.location_id));
            }

            return ids;
        },
        // Shelvable targets for the picker: anything without children, so a
        // room is browsable but not a shelf until it stops being subdivided.
        leaves(state) {
            const parents = new Set(
                state.allLocations
                    .map((location) => location.parent_id)
                    .filter(Boolean),
            );

            return state.allLocations.filter(
                (location) => !parents.has(location.location_id),
            );
        },
    },
    actions: {
        setAllLocations(locations) {
            this.allLocations = locations;
        },
        setCurrentLocation(payload) {
            this.currentLocation = payload;
        },
        async fetchAllLocations({ force = false } = {}) {
            if (this.allLocations.length && !force) {
                return this.allLocations;
            }

            const response = await getAllLocations();
            this.setAllLocations(response.data);

            return this.allLocations;
        },
        async fetchLocation(slug) {
            const response = await getOneLocation(slug);
            this.setCurrentLocation(response.data);

            return this.currentLocation;
        },
        // Every mutation refetches rather than patching in place, for the
        // same reason GenreStore does: the index payload carries
        // `versions_count`, which a move or a delete changes on rows the
        // mutation never named. None of them catch — a 409's body carries
        // the conflicting location and the caller needs it.
        async createLocation(attributes) {
            const response = await createLocationRequest(attributes);
            await this.fetchAllLocations({ force: true });

            return response.data;
        },
        async updateLocation(slug, attributes) {
            const response = await updateLocationRequest(slug, attributes);
            await this.fetchAllLocations({ force: true });

            return response.data;
        },
        async deleteLocation(slug, { force = false } = {}) {
            const response = await deleteLocationRequest(slug, { force });
            await this.fetchAllLocations({ force: true });

            return response.data;
        },
        async shelveVersion(version_id, location_id, shelf_ordinal = null) {
            const response = await setVersionLocationRequest(
                version_id,
                location_id,
                shelf_ordinal,
            );
            await this.fetchAllLocations({ force: true });

            return response.data;
        },
    },
});

export default useLocationsStore;
