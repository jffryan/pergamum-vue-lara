import axios from "axios";

export const bulkUpload = async (file) => {
    const formData = new FormData();
    formData.append("csv_file", file);
    return axios.post("/api/bulk-upload", formData, {
        headers: { "Content-Type": "multipart/form-data" },
    });
};
