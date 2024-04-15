<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use App\Models\Book;

class NewBookController extends Controller
{
    //
    public function createOrGetBookByTitle(Request $request)
    {
        $title = $request['title'];

        $slug = Str::of($title)
            ->lower()
            ->replaceMatches('/[^a-z0-9\s]/', '')  // Remove non-alphanumeric characters
            ->replace(' ', '-')  // Replace spaces with hyphens
            ->limit(30);  // Limit to 30 characters

        // Look for an existing book by the slug
        $existingBook = Book::with("authors", "genres", "versions", "versions.format", "versions.readInstances")->where('slug', $slug)->first();

        if ($existingBook) {
            return response()->json(
                [
                    'exists' => true,
                    'book' => $existingBook
                ],
            );
        }

        $data = [
            'title' => $title,
            'slug' => $slug,
        ];

        return response()->json(
            [
                'exists' => false,
                'book' => $data
            ],
        );
    }

    public function completeBookCreation($bookData)
    {
        DB::beginTransaction();

        try {
            // Create the main book record
            $book = Book::create([
                // Book details
            ]);

            // Assume $bookData contains related information for authors and genres
            foreach ($bookData['authors'] as $authorData) {
                // Create or associate author records
                $book->authors()->create($authorData);
            }

            // Similarly, create or associate other related entities
            // ...

            // If all operations are successful, commit the transaction
            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Book and related records created successfully.',
                'book' => $book,
            ]);
        } catch (\Exception $e) {
            // If any operation fails, roll back the transaction
            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Error occurred, creation aborted. ' . $e->getMessage(),
            ]);
        }
    }
}
