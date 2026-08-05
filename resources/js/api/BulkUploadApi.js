import axios from "axios";

/**
 * Options object rather than positional args: the caller list grows (dry-run preview,
 * import-into-a-list) faster than positional parameters stay readable.
 *
 * Optional fields are appended only when set, so the multipart body of a plain upload
 * is byte-identical to what it was before they existed.
 */
export const bulkUpload = async (
    file,
    { dryRun = false, listName = null } = {},
) => {
    const formData = new FormData();
    formData.append("csv_file", file);

    if (dryRun) {
        formData.append("dry_run", "1");
    }

    if (listName !== null && listName !== "") {
        formData.append("list_name", listName);
    }

    return axios.post("/api/bulk-upload", formData, {
        headers: { "Content-Type": "multipart/form-data" },
    });
};

/**
 * The inverse of bulkUpload: the whole catalog plus this user's reading state,
 * as a CSV the importer reads back without translation.
 *
 * Fetched as a blob rather than linked directly because the endpoint is behind
 * `auth:sanctum` — a plain <a href> would send the session cookie but bypass
 * the axios config the rest of the app relies on, and gives nowhere to surface
 * a failure.
 */
export const exportCatalog = async () =>
    axios.get("/api/export", { responseType: "blob" });
