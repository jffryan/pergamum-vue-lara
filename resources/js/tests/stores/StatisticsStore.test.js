import { describe, it, expect, beforeEach, vi } from "vitest";
import { setActivePinia, createPinia } from "pinia";
import useStatisticsStore, { scopeKey } from "@/stores/StatisticsStore";
import { getStatistics } from "@/api/StatisticsController";

vi.mock("@/api/StatisticsController", () => ({
    getStatistics: vi.fn(),
}));

const payload = (metrics, meta = {}) => ({
    data: {
        scope: { type: "user", id: null },
        metrics,
        meta: {
            catalogWide: [],
            shelfScoped: [],
            estimated: {},
            failed: [],
            ...meta,
        },
    },
});

describe("StatisticsStore", () => {
    let store;

    beforeEach(() => {
        setActivePinia(createPinia());
        store = useStatisticsStore();
        vi.clearAllMocks();
    });

    it("keys scopes by type and id", () => {
        expect(scopeKey()).toBe("user");
        expect(scopeKey("list", 12)).toBe("list:12");
    });

    it("fetches everything when no metrics are named", async () => {
        getStatistics.mockResolvedValue(payload({ totalBooks: 4 }));

        await store.fetch();

        expect(getStatistics).toHaveBeenCalledWith("user", null, null);
        expect(store.metricsFor("user")).toEqual({ totalBooks: 4 });
    });

    it("serves a second identical request from cache", async () => {
        getStatistics.mockResolvedValue(payload({ totalBooks: 4 }));

        await store.fetch();
        await store.fetch();

        expect(getStatistics).toHaveBeenCalledTimes(1);
    });

    it("requests only the metrics it does not already hold", async () => {
        getStatistics.mockResolvedValueOnce(payload({ totalBooks: 4 }));
        await store.fetch("user", null, ["totalBooks"]);

        getStatistics.mockResolvedValueOnce(payload({ readsByYear: [] }));
        await store.fetch("user", null, ["totalBooks", "readsByYear"]);

        // The dashboard warms the cache; the full statistics page pays only
        // for the delta.
        expect(getStatistics).toHaveBeenLastCalledWith("user", null, [
            "readsByYear",
        ]);
        expect(store.metricsFor("user")).toEqual({
            totalBooks: 4,
            readsByYear: [],
        });
    });

    it("does not re-request a metric the server failed to compute", async () => {
        getStatistics.mockResolvedValue(
            payload({}, { failed: ["totalBooks"] }),
        );

        await store.fetch("user", null, ["totalBooks"]);
        await store.fetch("user", null, ["totalBooks"]);

        expect(getStatistics).toHaveBeenCalledTimes(1);
        expect(store.metaFor("user").failed).toEqual(["totalBooks"]);
    });

    it("dedupes concurrent requests for the same scope", async () => {
        getStatistics.mockResolvedValue(payload({ totalBooks: 4 }));

        await Promise.all([store.fetch(), store.fetch(), store.fetch()]);

        expect(getStatistics).toHaveBeenCalledTimes(1);
    });

    it("keeps scopes apart", async () => {
        getStatistics.mockResolvedValueOnce(payload({ totalBooks: 4 }));
        await store.fetch();

        getStatistics.mockResolvedValueOnce(payload({ totalItems: 9 }));
        await store.fetch("list", 12);

        expect(store.metricsFor("user")).toEqual({ totalBooks: 4 });
        expect(store.metricsFor("list:12")).toEqual({ totalItems: 9 });
    });

    it("merges meta across partial fetches", async () => {
        getStatistics.mockResolvedValueOnce(
            payload({ totalBooks: 4 }, { catalogWide: ["totalBooks"] }),
        );
        await store.fetch("user", null, ["totalBooks"]);

        getStatistics.mockResolvedValueOnce(
            payload(
                { newestBooks: [] },
                { catalogWide: ["newestBooks"], shelfScoped: ["newestBooks"] },
            ),
        );
        await store.fetch("user", null, ["newestBooks"]);

        expect(store.metaFor("user").catalogWide).toEqual([
            "totalBooks",
            "newestBooks",
        ]);
        expect(store.metaFor("user").shelfScoped).toEqual(["newestBooks"]);
    });

    it("drops the cache on invalidate", async () => {
        getStatistics.mockResolvedValue(payload({ totalBooks: 4 }));

        await store.fetch();
        store.invalidate();
        await store.fetch();

        expect(getStatistics).toHaveBeenCalledTimes(2);
        expect(store.metricsFor("user")).toEqual({ totalBooks: 4 });
    });

    it("refetches from scratch on refresh", async () => {
        getStatistics.mockResolvedValueOnce(payload({ totalBooks: 4 }));
        await store.fetch();

        getStatistics.mockResolvedValueOnce(payload({ totalBooks: 5 }));
        await store.refresh();

        expect(getStatistics).toHaveBeenCalledTimes(2);
        expect(store.metricsFor("user")).toEqual({ totalBooks: 5 });
    });

    it("records the error and lets the caller handle it", async () => {
        const failure = new Error("Network down");
        getStatistics.mockRejectedValue(failure);

        await expect(store.fetch()).rejects.toThrow("Network down");
        expect(store.scopeState("user").error).toBe(failure);
        expect(store.scopeState("user").isLoading).toBe(false);
    });

    it("retries after a failure rather than caching it", async () => {
        getStatistics.mockRejectedValueOnce(new Error("Network down"));
        await expect(store.fetch()).rejects.toThrow();

        getStatistics.mockResolvedValueOnce(payload({ totalBooks: 4 }));
        await store.fetch();

        expect(getStatistics).toHaveBeenCalledTimes(2);
        expect(store.metricsFor("user")).toEqual({ totalBooks: 4 });
    });
});
