<?php

namespace App\Http\Controllers;

use App\Models\Author;
use App\Services\AuthorService;
use App\Support\Slugger;
use Illuminate\Http\Request;

class AuthorController extends Controller
{
    protected $authorService;

    public function __construct(AuthorService $authorService)
    {
        $this->authorService = $authorService;
    }

    public function index()
    {
        //
    }

    public function create()
    {
        //
    }

    public function store(Request $request)
    {
        //
    }

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
        $authors = [];
        foreach ($authors_data as $author) {
            $name = $author['name'];
            $slug = Slugger::for($name);

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

    public function edit($id)
    {
        //
    }

    public function update(Request $request, $id)
    {
        //
    }

    public function destroy($id)
    {
        //
    }
}
