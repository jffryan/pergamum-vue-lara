const splitAndNormalizeGenres = (genres) => {
  return genres.split(",").map((genre) => genre.trim().toLowerCase());
};

const dummyVar = "dummy";

export { splitAndNormalizeGenres, dummyVar };
