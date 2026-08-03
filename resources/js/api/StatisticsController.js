import { makeRequest, buildUrl } from "./apiHelpers";

/**
 * One endpoint serves every statistics surface.
 *
 * `scope` is what the numbers are about ("user", "list", later "author"), and
 * `scopeId` identifies it where the type alone isn't enough. Omitting
 * `metricKeys` asks for everything the scope supports; passing a list keeps
 * the response to what the surface actually renders.
 */
const getStatistics = async (
    scope = "user",
    scopeId = null,
    metricKeys = null,
) =>
    makeRequest("get", buildUrl("statistics", scope, scopeId), undefined, {
        ...(metricKeys?.length ? { metrics: metricKeys.join(",") } : {}),
    });

// eslint-disable-next-line import/prefer-default-export
export { getStatistics };
