import axios from "axios";

// Helper functions --------

// Make request
export const makeRequest = async (method, url, data) => {
  const config = {
    method: method,
    url: url,
    data: data,
  };

  const response = await axios(config);
  return response;
};

// Build URL
export const buildUrl = (entity, id) => {
  let url = `/api/${entity}/`;

  if (id) {
    url += id;
  }

  return url;
};

