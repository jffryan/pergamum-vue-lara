<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreVersionRequest;
use App\Models\Version;
use Illuminate\Http\Request;

class VersionController extends Controller
{
    public function addNewVersion(StoreVersionRequest $request)
    {
        $version = Version::create($request->versionAttributes());

        // refresh() so DB-side defaults (is_discarded) are in the payload —
        // the version table in the SPA reads them.
        return response()->json($version->refresh(), 201);
    }

    /**
     * Mark a copy as discarded — we used to own it, we no longer do.
     *
     * `discarded_at` is optional: much of the historical library was got rid
     * of at an unrecoverable point in the past, so omitting the key (or
     * sending null) records "discarded, date unknown". Omitting the key
     * entirely on a re-discard preserves whatever date is already there.
     */
    public function discard(Request $request, $version_id)
    {
        $validated = $request->validate([
            'discarded_at' => 'nullable|date',
        ]);

        $version = Version::findOrFail($version_id);

        $version->is_discarded = true;

        if ($request->has('discarded_at')) {
            $version->discarded_at = $validated['discarded_at'] ?? null;
        }

        $version->save();

        return response()->json($version->load('format'));
    }

    /**
     * Undo a discard — we have the copy again. Clears the date along with the
     * flag so a later re-discard doesn't inherit a stale one.
     */
    public function restore($version_id)
    {
        $version = Version::findOrFail($version_id);

        $version->fill([
            'is_discarded' => false,
            'discarded_at' => null,
        ])->save();

        return response()->json($version->load('format'));
    }
}
