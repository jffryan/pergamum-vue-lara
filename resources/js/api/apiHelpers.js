import axios from "axios";

// Helper functions --------

export const makeRequest = async (method, url, data, params) => {
    const config = {
        method,
        url,
        data,
        params,
    };

    const response = await axios(config);
    return response;
};

/**
 * `/api/<entity>/<segment>/<segment>…`
 *
 * The single-segment call (`buildUrl("lists", 12)`) is the common case; extra
 * segments exist for endpoints whose path carries more than an id, such as
 * `/api/statistics/list/12`. Falsy segments are dropped, so `buildUrl("lists")`
 * still yields the trailing-slash collection URL it always has.
 */
export const buildUrl = (entity, ...segments) => {
    const tail = segments
        .filter((segment) => segment || segment === 0)
        .join("/");

    return `/api/${entity}/${tail}`;
};
