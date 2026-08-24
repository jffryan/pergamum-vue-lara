import { describe, it, expect, beforeEach, vi } from "vitest";
import { setActivePinia, createPinia } from "pinia";
import useLocationsStore from "@/stores/LocationsStore";
import {
    getAllLocations,
    getOneLocation,
    createLocation,
    deleteLocation,
    setVersionLocation,
} from "@/api/LocationController";

vi.mock("@/api/LocationController", () => ({
    getAllLocations: vi.fn(),
    getOneLocation: vi.fn(),
    getLocationBooks: vi.fn(),
    createLocation: vi.fn(),
    updateLocation: vi.fn(),
    deleteLocation: vi.fn(),
    setVersionLocation: vi.fn(),
}));

const room = {
    location_id: 1,
    parent_id: null,
    code: "O",
    name: "Office",
    kind: "room",
    slug: "o",
    versions_count: 0,
};
const bookcase = {
    location_id: 2,
    parent_id: 1,
    code: "O1",
    name: null,
    kind: "bookcase",
    slug: "o1",
    versions_count: 0,
};
const shelf = {
    location_id: 3,
    parent_id: 2,
    code: "O1S1",
    name: null,
    kind: "shelf",
    slug: "o1s1",
    versions_count: 12,
};

describe("LocationsStore", () => {
    let store;

    beforeEach(() => {
        vi.clearAllMocks();
        setActivePinia(createPinia());
        store = useLocationsStore();
    });

    it("should have the correct initial state", () => {
        expect(store.allLocations).toEqual([]);
        expect(store.currentLocation).toBeNull();
    });

    it("should populate allLocations from the API once and cache", async () => {
        getAllLocations.mockResolvedValue({ data: [room, bookcase, shelf] });

        await store.fetchAllLocations();
        await store.fetchAllLocations();

        expect(getAllLocations).toHaveBeenCalledTimes(1);
        expect(store.allLocations).toHaveLength(3);
    });

    it("derives the hierarchy from parent_id", async () => {
        getAllLocations.mockResolvedValue({ data: [room, bookcase, shelf] });
        await store.fetchAllLocations();

        expect(store.roots).toEqual([room]);
        expect(store.childrenOf(1)).toEqual([bookcase]);
        expect(store.bySlug("o1s1")).toEqual(shelf);
    });

    it("labels a location with its ancestor path, preferring names", async () => {
        getAllLocations.mockResolvedValue({ data: [room, bookcase, shelf] });
        await store.fetchAllLocations();

        expect(store.pathLabel(shelf)).toBe("Office / O1 / O1S1");
        expect(store.pathLabel(room)).toBe("Office");
    });

    it("computes subtree ids over self and every descendant", async () => {
        getAllLocations.mockResolvedValue({ data: [room, bookcase, shelf] });
        await store.fetchAllLocations();

        expect(store.subtreeIdsOf(1)).toEqual([1, 2, 3]);
        expect(store.subtreeIdsOf(3)).toEqual([3]);
    });

    it("treats only unsubdivided locations as leaves", async () => {
        getAllLocations.mockResolvedValue({ data: [room, bookcase, shelf] });
        await store.fetchAllLocations();

        // The room and bookcase both have children, so the shelf is the one
        // shelvable target.
        expect(store.leaves).toEqual([shelf]);
    });

    it("stores the show payload as currentLocation", async () => {
        const payload = {
            location: bookcase,
            ancestors: [room],
            children: [shelf],
            subtree_versions_count: 12,
        };
        getOneLocation.mockResolvedValue({ data: payload });

        await store.fetchLocation("o1");

        expect(store.currentLocation).toEqual(payload);
    });

    it("refetches the tree after every mutation", async () => {
        getAllLocations.mockResolvedValue({ data: [room] });
        createLocation.mockResolvedValue({ data: room });
        deleteLocation.mockResolvedValue({ data: { deleted: true } });
        setVersionLocation.mockResolvedValue({ data: { version_id: 9 } });

        await store.createLocation({ code: "O", kind: "room" });
        await store.deleteLocation("o");
        await store.shelveVersion(9, 3);

        // One forced refetch per mutation — counts change on rows the
        // mutation never named.
        expect(getAllLocations).toHaveBeenCalledTimes(3);
    });

    it("does not swallow a 409 conflict", async () => {
        const conflict = new Error("Request failed with status code 409");
        conflict.response = {
            status: 409,
            data: { reason_code: "location_code_taken", conflict: room },
        };
        createLocation.mockRejectedValue(conflict);

        await expect(
            store.createLocation({ code: "O", kind: "room" }),
        ).rejects.toBe(conflict);
    });
});
