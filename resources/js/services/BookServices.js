import { getOneBookFromSlug } from "@/api/BookController";
import { createVersion } from "@/api/VersionController";

const addVersionToBookService = async (bookId, version) => {
    if (!bookId) throw new Error("Invalid book ID");

    try {
        const newVersion = { book_id: bookId, ...version };
        return await createVersion(newVersion);
    } catch (error) {
        console.error("Failed to add version:", error.message);
        throw new Error(`Failed to add version: ${error.message}`);
    }
};

function calculateRuntime(runtime) {
    if (!Number.isFinite(runtime) || runtime < 0) return "0h 0m";

    const hours = Math.floor(runtime / 60);
    const minutes = runtime % 60;
    return `${hours}h ${minutes}m`;
}

async function fetchBookData(slug) {
    if (!slug) throw new Error("Invalid book slug");

    try {
        const res = await getOneBookFromSlug(slug);
        if (!res.data || res.status !== 200) {
            throw new Error(
                "Failed to fetch book data: Invalid server response",
            );
        }
        return res.data;
    } catch (error) {
        console.error("Error fetching book data:", error.message);
        throw error;
    }
}

function formatDateRead(date) {
    if (!date || !date.includes("-")) return "Invalid Date";

    const [year, month, day] = date.split("-");
    if (!year || !month || !day) return "Invalid Date";

    const lastTwoDigitsOfYear = year.slice(-2);
    return `${month}/${day}/${lastTwoDigitsOfYear}`;
}

const splitAndNormalizeGenres = (genres) => {
    if (!genres || typeof genres !== "string") return [];

    return genres
        .split(",")
        .map((genre) => genre.trim().toLowerCase())
        .filter((genre) => genre !== "");
};

export {
    addVersionToBookService,
    calculateRuntime,
    fetchBookData,
    formatDateRead,
    splitAndNormalizeGenres,
};
