<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\Version;

class VersionController extends Controller
{
    public function addNewVersion(Request $request)
    {
        // Validate the request with dot-notation for nested fields
        $validated = $request->validate([
            'version.book_id'               => 'required|integer',
            'version.page_count'            => 'nullable|integer',
            'version.audio_runtime'         => 'nullable|integer',
            'version.format.format_id'      => 'required|integer',
        ]);

        // Build an array of data we actually need
        $versionData = [
            'book_id'       => $validated['version']['book_id'],
            'page_count'    => $validated['version']['page_count'] ?? null,
            'audio_runtime' => $validated['version']['audio_runtime'] ?? null,
            'format_id'     => $validated['version']['format']['format_id'],
        ];

        // Insert into the database
        $version = Version::create($versionData);

        return response()->json($version, 201);
    }
}