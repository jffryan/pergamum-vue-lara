<?php

namespace App\Http\Controllers;

use App\Models\Author;
use App\Services\AuthorService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;

class AuthorController extends Controller
{
    protected $authorService;

    public function __construct(AuthorService $authorService)
    {
        $this->authorService = $authorService;
    }

    /**
     * Display a listing of the resource.
     *
     * @return Response
     */
    public function index()
    {
        //
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
    public function show($slug)
    {
        $response = $this->authorService->getAuthorWithRelations($slug, 'slug');

        return response()->json($response);
    }

    public function getAuthorBySlug($slug)
    {
        $response = $this->authorService->getAuthorWithRelations($slug, 'slug');

        return response()->json($response);
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
