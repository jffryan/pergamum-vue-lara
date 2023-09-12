import axios from "axios";

// Helper functions --------

export const makeRequest = async (method, url, data) => {
  const config = {
    method,
    url,
    data,
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
