const splitAndNormalizeGenres = (genres) => {
  return genres
    .split(",")
    .map((genre) => genre.trim().toLowerCase())
    .filter((genre) => genre !== "");
};

function splitName(name) {
  const lastSpaceIndex = name.lastIndexOf(" ");

  if (lastSpaceIndex === -1) {
    return {
      first_name: "",
      last_name: name,
    };
  }

  const first_name = name.substring(0, lastSpaceIndex);
  const last_name = name.substring(lastSpaceIndex + 1);

  return {
    first_name,
    last_name,
  };
}

export { splitAndNormalizeGenres, splitName };
