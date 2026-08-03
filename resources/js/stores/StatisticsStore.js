import { defineStore } from "pinia";
import { getStatistics } from "@/api/StatisticsController";

/**
 * Cache key for a scope — "user", "list:12".
 */
export const scopeKey = (scope = "user", scopeId = null) =>
    scopeId === null || scopeId === undefined ? scope : `${scope}:${scopeId}`;

/**
 * In-flight requests, one per scope, kept outside the reactive state — a
 * promise has no business being a reactive value, and two surfaces mounting
 * at once should share a request rather than race.
 */
const inFlight = new Map();

const emptyScope = () => ({
    metrics: {},
    meta: { catalogWide: [], shelfScoped: [], estimated: {}, failed: [] },
    // Keys we have asked the server for, which is not the same as keys we
    // got back: a metric that failed server-side must not be re-requested on
    // every visit.
    requested: [],
    fetchedAll: false,
    isLoading: false,
    error: null,
});

const union = (a, b) => Array.from(new Set([...a, ...b]));

const useStatisticsStore = defineStore("StatisticsStore", {
    state: () => ({
        scopes: {},
    }),
    getters: {
        /**
         * Everything known about a scope, whether or not it's been fetched —
         * components can bind to this before the first request resolves.
         */
        scopeState: (state) => (key) => state.scopes[key] ?? emptyScope(),
        metricsFor: (state) => (key) => state.scopes[key]?.metrics ?? {},
        metaFor: (state) => (key) =>
            state.scopes[key]?.meta ?? emptyScope().meta,
    },
    actions: {
        ensureScope(key) {
            if (!this.scopes[key]) {
                this.scopes[key] = emptyScope();
            }

            return this.scopes[key];
        },

        /**
         * The keys still worth asking for: `null` means "everything", an empty
         * array means the cache already covers the request.
         */
        missingKeys(key, metricKeys) {
            const entry = this.ensureScope(key);

            if (!metricKeys?.length) {
                return entry.fetchedAll ? [] : null;
            }

            return metricKeys.filter(
                (metric) => !entry.requested.includes(metric),
            );
        },

        /**
         * Fetch a scope's metrics, requesting only what isn't cached.
         *
         * This is what makes /dashboard → /statistics cheap: the summary
         * surface warms the cache and the full surface fetches the delta.
         */
        async fetch(scope = "user", scopeId = null, metricKeys = null) {
            const key = scopeKey(scope, scopeId);

            // Ride along with a request already out for this scope; whatever it
            // brings back may cover us entirely.
            const pending = inFlight.get(key);
            if (pending) {
                await pending.catch(() => {});
            }

            const missing = this.missingKeys(key, metricKeys);
            if (missing !== null && missing.length === 0) {
                return this.scopes[key];
            }

            const request = this.request(key, scope, scopeId, missing);
            inFlight.set(key, request);

            try {
                return await request;
            } finally {
                inFlight.delete(key);
            }
        },

        async request(key, scope, scopeId, missing) {
            const entry = this.ensureScope(key);
            entry.isLoading = true;
            entry.error = null;

            try {
                const response = await getStatistics(scope, scopeId, missing);
                const { metrics, meta } = response.data;

                entry.metrics = { ...entry.metrics, ...metrics };
                // A key is fetched once per scope until invalidation, so the
                // meta lists can be unioned without deduping stale entries.
                entry.meta = {
                    catalogWide: union(
                        entry.meta.catalogWide,
                        meta?.catalogWide ?? [],
                    ),
                    shelfScoped: union(
                        entry.meta.shelfScoped,
                        meta?.shelfScoped ?? [],
                    ),
                    estimated: {
                        ...entry.meta.estimated,
                        ...(meta?.estimated ?? {}),
                    },
                    failed: union(entry.meta.failed, meta?.failed ?? []),
                };
                entry.requested = union(
                    entry.requested,
                    missing ?? Object.keys(metrics ?? {}),
                );

                if (missing === null) {
                    entry.fetchedAll = true;
                }

                return entry;
            } catch (error) {
                entry.error = error;
                throw error;
            } finally {
                entry.isLoading = false;
            }
        },

        /**
         * Drop a scope's cache. Called after anything that changes the numbers
         * — a read instance, a list mutation.
         */
        invalidate(scope = "user", scopeId = null) {
            delete this.scopes[scopeKey(scope, scopeId)];
        },

        invalidateAll() {
            this.scopes = {};
        },

        /**
         * Re-fetch from scratch; backs the retry button on a failed surface.
         */
        async refresh(scope = "user", scopeId = null, metricKeys = null) {
            this.invalidate(scope, scopeId);

            return this.fetch(scope, scopeId, metricKeys);
        },
    },
});

export default useStatisticsStore;
