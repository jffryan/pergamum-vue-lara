import { getOneBookFromSlug } from "@/api/BookController";

async function fetchBookData(slug) {
    try {
        const res = await getOneBookFromSlug(slug);
        if (!res.data || res.status !== 200) {
            throw new Error(
                "Failed to fetch data: Invalid response from the server",
            );
        }
        return res.data;
    } catch (error) {
        console.log("ERROR: ", error);
        return error;
    }
}

function formatDateRead(date) {
    // MM/DD/YY
    const [year, month, day] = date.split("-");
    const lastTwoDigitsOfYear = year.slice(-2);
    const formattedDateRead = `${month}/${day}/${lastTwoDigitsOfYear}`;
    return formattedDateRead;
}

export { fetchBookData, formatDateRead };
