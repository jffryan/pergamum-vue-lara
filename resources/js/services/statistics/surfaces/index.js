import userStatistics from "./userStatistics";
import userDashboard from "./userDashboard";
import listStatistics from "./listStatistics";

/**
 * Surface key -> config. A statistics page is an entry here plus a route
 * pointing a view at `<StatisticsGrid :surface="…" />`.
 */
const surfaces = {
    userStatistics,
    userDashboard,
    listStatistics,
};

export const getSurface = (key) => surfaces[key] ?? null;

export default surfaces;
