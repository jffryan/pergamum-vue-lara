<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\Genre;
use App\Services\BookService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class GenreController extends Controller
{
    protected $bookService;

    public function __construct(BookService $bookService)
    {
        $this->bookService = $bookService;
    }

    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index()
    {
        $genres = Genre::withCount('books')
            ->orderByRaw('CASE WHEN name REGEXP "^[0-9]" THEN 2 ELSE 1 END, name')
            ->get();

        return response()->json($genres);
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @return Response
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return Response
     */
    public function show(Request $request, $genre_id)
    {
        $genre = Genre::findOrFail($genre_id);

        $query = Book::with('authors', 'versions', 'versions.format', 'genres', 'readInstances')
            ->selectRaw('books.book_id, books.title, books.slug, MIN(authors.last_name) as primary_author_last_name')
            ->leftJoin('book_author', 'books.book_id', '=', 'book_author.book_id')
            ->leftJoin('authors', 'authors.author_id', '=', 'book_author.author_id')
            ->leftJoin('read_instances', 'books.book_id', '=', 'read_instances.book_id')
            ->whereHas('genres', function ($q) use ($genre_id) {
                $q->where('genres.genre_id', $genre_id);
            })
            ->groupBy('books.book_id', 'books.title', 'books.slug');

        // Sort the books based on the last name of the first author
        $query->orderBy('primary_author_last_name', 'asc');

        // Determine the pagination size, default to 20 if not specified
        $pageSize = $request->input('limit', 20);

        // Paginate the results
        $books = $query->paginate($pageSize);

        $formattedBooks = $this->bookService->getBooksList(collect($books->items()));

        // Return paginated results
        return response()->json([
            'genre' => [
                'genre_id' => $genre->genre_id,
                'name' => $genre->name,
            ],
            'books' => $formattedBooks,
            'pagination' => [
                'total' => $books->total(),
                'perPage' => $books->perPage(),
                'currentPage' => $books->currentPage(),
                'lastPage' => $books->lastPage(),
                'from' => $books->firstItem(),
                'to' => $books->lastItem(),
            ],
        ]);
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return Response
     */
    public function edit($id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  int  $id
     * @return Response
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return Response
     */
    public function destroy($id)
    {
        //
    }
}
