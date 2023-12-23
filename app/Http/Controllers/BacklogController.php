<?php

namespace App\Http\Controllers;

use App\Models\BacklogItem;
use Illuminate\Http\Request;

class BacklogController extends Controller
{
  public function index(Request $request)
  {
      $query = BacklogItem::with(['book' => function($query) {
                          $query->with('authors', 'versions', 'versions.format', 'genres');
                      }])
          ->join('books', 'backlog_items.book_id', '=', 'books.book_id')
          ->leftJoin('book_author', 'books.book_id', '=', 'book_author.book_id')
          ->leftJoin('authors', 'authors.author_id', '=', 'book_author.author_id')
          ->selectRaw('backlog_items.*, books.book_id, books.title, books.slug, books.is_completed, books.rating, books.date_completed,
                       (SELECT MIN(a.last_name) FROM authors a JOIN book_author ba ON a.author_id = ba.author_id WHERE ba.book_id = books.book_id) as primary_author_last_name');
  
      // Additional query conditions (format, sort_by, etc.)
      // ...
  
      $limit = $request->has('limit') ? $request->limit : 20;
      $backlogItems = $query->paginate($limit);
  
      // Transform the results
      return $backlogItems->through(function ($item) {
          $book = $item->book->toArray();
          unset($item->book); // Remove the nested book object
          $transformed = array_merge($item->toArray(), $book);
          $transformed['authors'] = $book['authors'];
          $transformed['versions'] = $book['versions'];
          $transformed['genres'] = $book['genres'];
          return $transformed;
      });
  }
}


/*
public function index(Request $request)
{
    $query = BacklogItem::with(['book' => function($query) {
                        $query->with('authors', 'versions', 'versions.format', 'genres');
                    }])
        ->join('books', 'backlog_items.book_id', '=', 'books.book_id')
        ->leftJoin('book_author', 'books.book_id', '=', 'book_author.book_id')
        ->leftJoin('authors', 'authors.author_id', '=', 'book_author.author_id')
        ->selectRaw('backlog_items.*,
                     books.book_id, books.title, books.slug, books.is_completed, books.rating, books.date_completed,
                     MIN(authors.last_name) as primary_author_last_name')
        ->groupBy('backlog_items.id', 'books.book_id', 'books.title', 'books.slug', 'books.is_completed', 'books.rating', 'books.date_completed');

    // Additional query conditions (format, sort_by, etc.)
    // ...

    $limit = $request->has('limit') ? $request->limit : 20;
    $backlogItems = $query->paginate($limit);

    // Transform the results
    return $backlogItems->through(function ($item) {
        $book = $item->book->toArray();
        unset($item->book); // Remove the nested book object
        $transformed = array_merge($item->toArray(), $book);
        $transformed['authors'] = $book['authors'];
        $transformed['versions'] = $book['versions'];
        $transformed['genres'] = $book['genres'];
        return $transformed;
    });
}


*/