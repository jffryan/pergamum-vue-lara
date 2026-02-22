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

export const buildUrl = (entity, id) => {
    let url = `/api/${entity}/`;

    if (id) {
        url += id;
    }

    return url;
};
