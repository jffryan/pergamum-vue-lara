<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Author;
use Illuminate\Support\Str;

class AuthorController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function index()
    {
        //
    }

    /**
     * Show the form for creating a new resource.
     *
     * @return \Illuminate\Http\Response
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @return \Illuminate\Http\Response
     */
    public function store(Request $request)
    {
        //
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function show($slug)
    {
        return Author::with('books', 'books.genres')
            ->where('slug', $slug)
            ->firstOrFail();
    }

    /**
     * Display the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function getAuthorBySlug($slug)
    {
        return Author::with('books', 'books.genres', 'books.authors', 'books.versions', 'books.versions.format')
            ->where('slug', $slug)
            ->firstOrFail();
    }

    public function getOrSetToBeCreatedAuthorsByName(Request $request)
    {
        $authors_data = $request['authorsData'];
        // Process each author to grab the full name along with first_name and last_name
        $authors = [];
        foreach ($authors_data as $author) {
            $name = $author['name'];
            $slug = Str::of($name)
                ->lower()
                ->replaceMatches('/[^a-z0-9\s]/', '')  // Remove non-alphanumeric characters
                ->replace(' ', '-')  // Replace spaces with hyphens
                ->limit(30);  // Limit to 30 characters
    
            // Look for an existing author by the slug
            $existingAuthor = Author::where('slug', $slug)->first();
    
            if ($existingAuthor) {
                $authors[] = $existingAuthor;
            } else {
                $data = [
                    'author_id' => null,
                    'first_name' => $author['first_name'], // Include first name
                    'last_name' => $author['last_name'],  // Include last name
                    'slug' => $slug,
                ];
    
                $authors[] = $data;
            }
        }
    
        return response()->json(['authors' => $authors]);
    }
    

    /**
     * Show the form for editing the specified resource.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function edit($id)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     *
     * @param  \Illuminate\Http\Request  $request
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function update(Request $request, $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param  int  $id
     * @return \Illuminate\Http\Response
     */
    public function destroy($id)
    {
        //
    }
}
