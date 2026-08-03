// eslint-disable-next-line
import { describe, it, expect, vi, beforeEach } from "vitest";
import axios from "axios";
import { getStatistics } from "@/api/StatisticsController";

vi.mock("axios");

const sentConfig = () => axios.mock.calls[0][0];

describe("getStatistics", () => {
    beforeEach(() => {
        vi.clearAllMocks();
        axios.mockResolvedValue({ data: { scope: {}, metrics: {}, meta: {} } });
    });

    it("defaults to the user scope", async () => {
        await getStatistics();

        expect(sentConfig().method).toBe("get");
        expect(sentConfig().url).toBe("/api/statistics/user");
    });

    it("puts the scope and its id in the path", async () => {
        await getStatistics("list", 12);

        expect(sentConfig().url).toBe("/api/statistics/list/12");
    });

    it("sends requested metrics as a comma-separated param", async () => {
        await getStatistics("user", null, ["totalBooks", "readsByYear"]);

        expect(sentConfig().params).toEqual({
            metrics: "totalBooks,readsByYear",
        });
    });

    it("omits the metrics param when asking for everything", async () => {
        await getStatistics("user", null, null);
        expect(sentConfig().params).toEqual({});

        vi.clearAllMocks();
        axios.mockResolvedValue({ data: {} });
        await getStatistics("user", null, []);
        expect(sentConfig().params).toEqual({});
    });

    it("returns the axios response", async () => {
        const response = await getStatistics();

        expect(response.data.metrics).toEqual({});
    });
});
