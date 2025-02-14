import axios from "axios";

import { makeRequest, buildUrl } from "./apiHelpers";

// GET ALL
const getAllBooks = async (options = {}) =>
    makeRequest("get", buildUrl("books"), null, options);

// GET ONE
const getOneBook = async (book_id) =>
    makeRequest("get", buildUrl("books", book_id));

// GET BY SLUG
const getOneBookFromSlug = async (slug) => {
    return makeRequest("get", buildUrl("book", slug));
};

// GET BY FORMAT
const getBooksByFormat = async (options) => {
    return makeRequest("get", buildUrl("books"), null, options);
};

// GET BY YEAR
const getBooksByYear = async (year) => {
    return makeRequest("get", buildUrl(`completed/${year}`));
};

// CREATE
const createBook = async (book) =>
    makeRequest("post", buildUrl("books"), { book });

const createBooks = async (books) => {
    return makeRequest("post", buildUrl("books", "bulk"), { books });
};

// UPDATE
const updateBook = async (request) => {
    return makeRequest("patch", buildUrl("books", request.book_id), {
        request,
    });
};

// DELETE
const deleteBook = async (book_id) =>
    makeRequest("delete", buildUrl("books", book_id));

const createOrGetBookByTitle = async (title) => {
    const url = buildUrl("create-book", "title");
    return makeRequest("POST", url, { title });
};

const submitNewBook = async (bookData) => {
    const url = buildUrl("create-book");
    return makeRequest("POST", url, { bookData });
};

// ------------------------------
// Let's come back to these two functions later
// ------------------------------
//
// REMOVE GENRE INSTANCE
const removeGenreInstance = async (deleteRequest) => {
    const url = "/api/book/update-genre";
    const request = {
        request: deleteRequest,
    };
    const response = await axios.post(url, request);
    return response;
};

// REMOVE AUTHOR INSTANCE
const removeAuthorInstance = async (deleteRequest) => {
    const url = "/api/book/update-author";
    const request = {
        request: deleteRequest,
    };
    const response = await axios.post(url, request);
    return response;
};

export {
    getAllBooks,
    getOneBookFromSlug,
    getOneBook,
    getBooksByFormat,
    getBooksByYear,
    createBook,
    createBooks,
    updateBook,
    removeGenreInstance,
    removeAuthorInstance,
    deleteBook,
    createOrGetBookByTitle,
    submitNewBook,
};
