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

const Book = "Book";

export { fetchBookData, Book };
