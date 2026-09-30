import { makeRequest, buildUrl } from "./apiHelpers";

const getAuthorBySlug = async (slug) =>
    makeRequest("get", buildUrl("author", slug));

// Every author with `books_count`, in filing order — the admin list.
const getAllAuthors = async (options = {}) =>
    makeRequest("get", buildUrl("authors"), null, options);

// A rename moves the author's slug with the name, so the response's `slug` is
// the author page's new URL. A name another author already holds is a 409
// carrying them as `conflict`.
const updateAuthor = async (author_id, { first_name, last_name }) =>
    makeRequest("patch", buildUrl("authors", author_id), {
        first_name,
        last_name,
    });

// `keep_id` is the winner; `source_ids` are folded into it and deleted.
const mergeAuthors = async (keep_id, source_ids) =>
    makeRequest("post", buildUrl("authors", keep_id, "merge"), { source_ids });

export { getAuthorBySlug, getAllAuthors, updateAuthor, mergeAuthors };
