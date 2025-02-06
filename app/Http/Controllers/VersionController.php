<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Version;

class VersionController extends Controller
{
    public function addNewVersion(Request $request)
    {
        $validatedData = $request->validate([
            'book_id' => 'required|integer',
            'page_count' => 'nullable|integer',
            'audio_runtime' => 'nullable|integer',
            'format_id' => 'required|integer',
        ]);

        $version = Version::create([
            'book_id' => $validatedData['book_id'],
            'page_count' => $validatedData['page_count'],
            'audio_runtime' => $validatedData['audio_runtime'],
            'format_id' => $validatedData['format_id'],
        ]);

        return response()->json($version, 201);
    }
}