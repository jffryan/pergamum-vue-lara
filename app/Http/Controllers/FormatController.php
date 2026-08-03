<?php

namespace App\Http\Controllers;

use App\Models\Format;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class FormatController extends Controller
{
    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255|unique:formats,name',
            'expects_page_count' => 'sometimes|boolean',
            'expects_audio_runtime' => 'sometimes|boolean',
        ]);

        // Defaults describe a print format, which is what most new formats are;
        // an admin adding a spoken-word medium flips the pair explicitly.
        $format = Format::create([
            'name' => $validated['name'],
            'slug' => Str::slug($validated['name']),
            'expects_page_count' => $validated['expects_page_count'] ?? true,
            'expects_audio_runtime' => $validated['expects_audio_runtime'] ?? false,
        ]);

        return response()->json($format, 201);
    }
}
