import { defineStore } from "pinia";

const useBooksStore = defineStore("BooksStore", {
    // Ordering is not state here. The library listing is paginated, so its
    // sort is a query parameter resolved by the server — see
    // BookListing::SORTABLE. A client-side `sortedBy` could only ever
    // reorder the page already fetched.
    state: () => ({
        allBooks: [],
    }),
    actions: {
        setAllBooks(books) {
            this.allBooks = books;
        },
        addBook(book) {
            const existingBook = this.allBooks.find(
                (b) => b.book.book_id === book.book.book_id,
            );

            if (existingBook) {
                // Deduplicate versions
                const mergedVersions = new Map();
                [...existingBook.versions, ...book.versions].forEach((v) => {
                    mergedVersions.set(v.version_id, v);
                });
                existingBook.versions = Array.from(mergedVersions.values());
            } else {
                this.allBooks.push(book);
            }
        },
        replaceVersion(bookId, version) {
            const book = this.allBooks.find((b) => b.book.book_id === bookId);
            if (!book) return;

            const index = book.versions.findIndex(
                (v) => v.version_id === version.version_id,
            );
            if (index === -1) return;

            book.versions[index] = { ...book.versions[index], ...version };
        },
        updateBook(book) {
            const index = this.allBooks.findIndex(
                (b) => b.book.book_id === book.book.book_id,
            );
            this.allBooks[index] = book;
        },
        // Swap `author` into every cached book that credits it — or, after a
        // merge, credits one of `replacedIds`. A book that credited both the
        // winner and a loser keeps one entry, the way the server now does.
        replaceAuthor(author, replacedIds = []) {
            const ids = [author.author_id, ...replacedIds];
            const fields = {
                author_id: author.author_id,
                first_name: author.first_name,
                last_name: author.last_name,
                slug: author.slug,
            };

            this.allBooks.forEach((book, index) => {
                if (!book.authors?.some((a) => ids.includes(a.author_id))) {
                    return;
                }

                const seen = new Set();
                this.allBooks[index] = {
                    ...book,
                    authors: book.authors
                        .map((a) =>
                            ids.includes(a.author_id) ? { ...a, ...fields } : a,
                        )
                        .filter((a) => {
                            if (seen.has(a.author_id)) return false;
                            seen.add(a.author_id);
                            return true;
                        }),
                };
            });
        },
        // Add `genres` to every cached book in `bookIds` that lacks them —
        // the client half of `POST /books/bulk-tag`. Kept in name order, the
        // order the server eager-loads them in.
        addGenres(bookIds, genres) {
            this.allBooks.forEach((book, index) => {
                if (!bookIds.includes(book.book.book_id)) return;

                const current = book.genres || [];
                const held = new Set(current.map((g) => g.genre_id));
                const added = genres.filter((g) => !held.has(g.genre_id));
                if (!added.length) return;

                this.allBooks[index] = {
                    ...book,
                    genres: [...current, ...added].sort((a, b) =>
                        a.name.localeCompare(b.name),
                    ),
                };
            });
        },
    },
});

export default useBooksStore;
