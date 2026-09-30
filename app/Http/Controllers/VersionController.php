<?php

namespace App\Http\Controllers;

use App\Http\Requests\MoveVersionRequest;
use App\Http\Requests\StoreVersionRequest;
use App\Models\Version;
use App\Services\Exceptions\CopyDiscardedException;
use App\Services\LocationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class VersionController extends Controller
{
    public function __construct(protected LocationService $locationService) {}

    /**
     * Add a copy to an existing book, optionally shelving it as it's made.
     *
     * Shelving goes through `LocationService::shelveVersion` — the picker's
     * call, and the new-book door's — inside the same transaction as the
     * insert, so a copy is never left half-made on a bad shelf. A fresh copy
     * is never discarded, so the service's discarded-copy refusal can't fire.
     */
    public function addNewVersion(StoreVersionRequest $request)
    {
        $version = DB::transaction(function () use ($request) {
            $version = Version::create($request->versionAttributes());

            if ($request->locationId() !== null) {
                $this->locationService->shelveVersion($version, $request->locationId());
            }

            return $version;
        });

        // refresh() so DB-side defaults (is_discarded) are in the payload,
        // and `format` / `location` because the book page's copy row renders
        // both — the SPA appends this response to the book it holds.
        return response()->json($version->refresh()->load('format', 'location'), 201);
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

        // A copy you no longer own is not on a shelf: it moves to the
        // virtual "Discarded" location, which is derived from `is_discarded`
        // (see `VirtualLocations`), and `shelveVersion` refuses to put it
        // back on one while it stays discarded. Restoring does not reshelve
        // — the copy comes back "unshelved" and gets placed by hand.
        $version->location_id = null;
        $version->shelf_ordinal = null;

        $version->save();

        // `location` and not just `format`: the SPA merges this response over
        // the copy it already holds, so a response that omits the relation
        // leaves the shelf it was just taken off of sitting in the payload.
        return response()->json($version->load('format', 'location'));
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

        return response()->json($version->load('format', 'location'));
    }

    /**
     * Shelve, reshelve, or unshelve one copy — `location_id: null` unshelves.
     * The single-FK update is the whole point of locations over lists. A
     * discarded copy is a 422: it is in the discarded pile, not on a shelf.
     */
    public function setLocation(MoveVersionRequest $request, $version_id)
    {
        $version = Version::findOrFail($version_id);

        try {
            $this->locationService->shelveVersion($version, $request->locationId(), $request->shelfOrdinal());
        } catch (CopyDiscardedException $e) {
            return response()->json([
                'reason_code' => $e->reasonCode,
                'reason' => $e->getMessage(),
            ], 422);
        }

        return response()->json($version->load('format', 'location'));
    }
}
