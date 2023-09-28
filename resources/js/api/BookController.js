import axios from "axios";

import { makeRequest, buildUrl } from "./apiHelpers";

// GET ALL
const getAllBooks = async (options = {}) =>
  makeRequest("get", buildUrl("books"), null, options);

// GET ONE
const getOneBook = async (book_id) =>
  makeRequest("get", buildUrl("books", book_id));

// GET BY SLUG
const getBookBySlug = async (slug) =>
  makeRequest("get", buildUrl("book", slug));

// CREATE
const createBook = async (book) =>
  makeRequest("post", buildUrl("books"), { book });

// UPDATE
const updateBook = async (book) =>
  makeRequest("patch", buildUrl("books", book.book_id), { book });

// DELETE
const deleteBook = async (book_id) =>
  makeRequest("delete", buildUrl("books", book_id), { book_id });

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
  getBookBySlug,
  getOneBook,
  createBook,
  updateBook,
  removeGenreInstance,
  removeAuthorInstance,
  deleteBook,
};
