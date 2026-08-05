// eslint-disable-next-line
import { describe, it, expect, vi, beforeEach } from "vitest";
import axios from "axios";
import { bulkUpload, exportCatalog } from "@/api/BulkUploadApi";

vi.mock("axios");

const file = new File(["title,authors,format\n"], "books.csv", {
    type: "text/csv",
});

/**
 * The FormData axios was called with, as a plain object.
 */
const sentBody = () => {
    const [, formData] = axios.post.mock.calls[0];
    return Object.fromEntries(formData.entries());
};

describe("bulkUpload", () => {
    beforeEach(() => {
        vi.clearAllMocks();
        axios.post.mockResolvedValue({ data: { summary: {}, results: [] } });
    });

    it("posts the file as multipart to the bulk-upload endpoint", async () => {
        await bulkUpload(file);

        expect(axios.post).toHaveBeenCalledTimes(1);
        const [url, , config] = axios.post.mock.calls[0];
        expect(url).toBe("/api/bulk-upload");
        expect(config.headers["Content-Type"]).toBe("multipart/form-data");
        expect(sentBody().csv_file).toBeInstanceOf(File);
    });

    it("omits both optional fields when called with no options", async () => {
        await bulkUpload(file);

        expect(Object.keys(sentBody())).toEqual(["csv_file"]);
    });

    it("sends dry_run only when requested", async () => {
        await bulkUpload(file, { dryRun: true });

        expect(sentBody().dry_run).toBe("1");
    });

    it("omits dry_run when explicitly false", async () => {
        await bulkUpload(file, { dryRun: false });

        expect(sentBody().dry_run).toBeUndefined();
    });

    it("sends list_name when provided", async () => {
        await bulkUpload(file, { listName: "Summer 2026 haul" });

        expect(sentBody().list_name).toBe("Summer 2026 haul");
    });

    it("omits list_name when null or empty", async () => {
        await bulkUpload(file, { listName: null });
        expect(sentBody().list_name).toBeUndefined();

        vi.clearAllMocks();
        axios.post.mockResolvedValue({ data: {} });
        await bulkUpload(file, { listName: "" });
        expect(sentBody().list_name).toBeUndefined();
    });

    it("sends both options together", async () => {
        await bulkUpload(file, { dryRun: true, listName: "Preview me" });

        expect(sentBody()).toMatchObject({
            dry_run: "1",
            list_name: "Preview me",
        });
    });

    it("returns the axios response", async () => {
        const response = await bulkUpload(file);

        expect(response.data).toEqual({ summary: {}, results: [] });
    });
});

describe("exportCatalog", () => {
    beforeEach(() => {
        vi.clearAllMocks();
        axios.get.mockResolvedValue({ data: new Blob(["title,authors\n"]) });
    });

    it("gets the export endpoint as a blob", async () => {
        await exportCatalog();

        expect(axios.get).toHaveBeenCalledTimes(1);
        const [url, config] = axios.get.mock.calls[0];
        expect(url).toBe("/api/export");
        // Without this the CSV arrives parsed as a string and a download
        // built from it can corrupt anything non-ASCII in a title.
        expect(config.responseType).toBe("blob");
    });

    it("returns the axios response", async () => {
        const response = await exportCatalog();

        expect(response.data).toBeInstanceOf(Blob);
    });
});
